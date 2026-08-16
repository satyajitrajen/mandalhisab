<?php

namespace App\Services;

use App\Enums\FundBucket;
use App\Enums\HandoverStatus;
use App\Enums\MoneyTrailType;
use App\Models\BankAccount;
use App\Models\CashHandover;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\FundTransfer;
use App\Models\MoneyTrailEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class FundService
{
    /**
     * Read summary buckets from the explicit FestivalBalance ledger (O(1)).
     */
    public function getSummary(string $festivalId): array
    {
        $balance = FestivalBalance::forFestival($festivalId);
        $festival = Festival::findOrFail($festivalId);

        return [
            'festival_id' => $festivalId,
            'opening_balance' => (float) $festival->opening_balance,
            'buckets' => [
                'cash_treasurer' => (float) $balance->cash_treasurer,
                'cash_collectors' => (float) $balance->cash_collectors,
                'bank' => (float) $balance->bank,
                'upi' => (float) $balance->upi,
            ],
        ];
    }

    /**
     * Query the money trail with optional filters.
     */
    public function getMoneyTrail(string $festivalId, array $filters = [])
    {
        $query = MoneyTrailEntry::where('festival_id', $festivalId);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Create a cash handover request.
     */
    public function createHandover(string $festivalId, array $data, string $fromUserId): CashHandover
    {
        return DB::transaction(function () use ($festivalId, $data, $fromUserId) {
            $toUserId = $data['to_user_id'] ?? null;
            if (! $toUserId) {
                $festival = Festival::find($festivalId);
                if ($festival) {
                    $treasurer = \App\Models\MandalMember::where('mandal_id', $festival->mandal_id)
                        ->whereIn('role', [\App\Enums\MemberRole::TREASURER, \App\Enums\MemberRole::ADMIN, \App\Enums\MemberRole::SUPER_ADMIN])
                        ->where('is_active', true)
                        ->first();
                    $toUserId = $treasurer?->user_id;
                }
                $toUserId = $toUserId ?? $fromUserId;
            }

            $handover = CashHandover::create([
                'festival_id' => $festivalId,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'amount' => $data['amount'],
                'linked_entry_ids' => $data['linked_entry_ids'] ?? [],
                'linked_entries_count' => $data['linked_entries_count'] ?? 0,
                'linked_date_range' => $data['linked_date_range'] ?? null,
                'notes' => $data['notes'] ?? null,
                'photo_url' => $data['photo_url'] ?? null,
                'status' => HandoverStatus::PENDING_APPROVAL,
            ]);

            return $handover;
        });
    }

    /**
     * Verify (accept or reject) a cash handover.
     * On accept: moves cash from collectors → treasurer in the balance ledger.
     */
    public function verifyHandover(string $handoverId, string $action, string $authMethod, ?string $notes = null): CashHandover
    {
        return DB::transaction(function () use ($handoverId, $action, $authMethod, $notes) {
            $handover = CashHandover::lockForUpdate()->findOrFail($handoverId);

            $status = match ($action) {
                'accept' => HandoverStatus::VERIFIED_ACCEPTED,
                'reject' => HandoverStatus::REJECTED,
                default => throw new \InvalidArgumentException('Invalid handover action. Use accept or reject.'),
            };

            $handover->status = $status;
            $handover->auth_method = $authMethod;
            $handover->verification_notes = $notes;
            $handover->verified_at = now();
            $handover->save();

            if ($status === HandoverStatus::VERIFIED_ACCEPTED) {
                $balance = FestivalBalance::forFestival($handover->festival_id);
                $balance->addToBucket('cash_collectors', -(float) $handover->amount);
                $balance->addToBucket('cash_treasurer', (float) $handover->amount);

                MoneyTrailEntry::create([
                    'festival_id' => $handover->festival_id,
                    'type' => MoneyTrailType::CASH_HANDOVER,
                    'title' => 'Cash Handover Verified',
                    'subtitle' => 'Collector → Treasurer',
                    'amount' => $handover->amount,
                    'is_positive' => true,
                    'reference_id' => $handover->id,
                    'reference_type' => CashHandover::class,
                ]);
            }

            return $handover;
        });
    }

    /**
     * Create a bank account for a festival.
     */
    public function createBankAccount(string $festivalId, array $data): BankAccount
    {
        return BankAccount::create([
            'festival_id' => $festivalId,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'ifsc' => $data['ifsc'] ?? null,
            'account_type' => $data['account_type'],
            'balance' => $data['balance'] ?? 0,
            'upi_id' => $data['upi_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Update bank account details.
     */
    public function updateBankAccount(string $accountId, array $data): BankAccount
    {
        $account = BankAccount::findOrFail($accountId);
        $account->fill($data);
        $account->save();

        return $account;
    }

    /**
     * Transfer funds between buckets. Validates sufficient source balance via FestivalBalance.
     */
    public function transferFunds(string $festivalId, array $data, string $userId): FundTransfer
    {
        return DB::transaction(function () use ($festivalId, $data, $userId) {
            $fromBucket = FundBucket::from($data['from_bucket']);
            $toBucket = FundBucket::from($data['to_bucket']);
            $amount = (float) $data['amount'];

            $bucketMap = [
                FundBucket::CASH_TREASURER->value => 'cash_treasurer',
                FundBucket::CASH_COLLECTORS->value => 'cash_collectors',
                FundBucket::BANK->value => 'bank',
                FundBucket::UPI->value => 'upi',
            ];

            $sourceColumn = $bucketMap[$fromBucket->value];
            $destColumn = $bucketMap[$toBucket->value];

            // Lock the balance row for this festival
            $balance = FestivalBalance::lockForUpdate()
                ->where('festival_id', $festivalId)
                ->first();

            if (! $balance) {
                try {
                    $balance = FestivalBalance::create([
                        'festival_id' => $festivalId,
                        'cash_treasurer' => 0,
                        'cash_collectors' => 0,
                        'bank' => 0,
                        'upi' => 0,
                        'version' => 0,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    $balance = FestivalBalance::lockForUpdate()
                        ->where('festival_id', $festivalId)
                        ->firstOrFail();
                }
            }

            $sourceBalance = (float) $balance->{$sourceColumn};
            if ($sourceBalance < $amount) {
                throw new \InvalidArgumentException('Insufficient balance in source bucket.');
            }

            $balance->addToBucket($sourceColumn, -$amount);
            $balance->addToBucket($destColumn, $amount);
            $balance->increment('version');

            $transfer = FundTransfer::create([
                'festival_id' => $festivalId,
                'from_bucket' => $fromBucket,
                'to_bucket' => $toBucket,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $userId,
            ]);

            MoneyTrailEntry::create([
                'festival_id' => $festivalId,
                'type' => MoneyTrailType::FUND_TRANSFER,
                'title' => 'Fund Transfer',
                'subtitle' => "{$fromBucket->value} → {$toBucket->value}",
                'amount' => $amount,
                'is_positive' => false,
                'reference_id' => $transfer->id,
                'reference_type' => FundTransfer::class,
            ]);

            return $transfer;
        });
    }
}
