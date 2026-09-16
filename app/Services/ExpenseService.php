<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\MoneyTrailType;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Create an expense. Validates bill file or pending reason.
     */
    public function createExpense(string $festivalId, array $data, string $userId): ExpenseEntry
    {
        // Offline replays can resend the same clientUuid after a timeout;
        // return the original entry instead of creating a duplicate.
        if (! empty($data['client_uuid'])) {
            $existing = ExpenseEntry::where('festival_id', $festivalId)
                ->where('client_uuid', $data['client_uuid'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $billUrl = $data['bill_url'] ?? null;
        if (! $billUrl && ! empty($data['bill_file'])) {
            $billUrl = $this->storeBill($festivalId, $data['bill_file']);
        }

        if (empty($billUrl) && empty($data['bill_pending_reason'])) {
            throw new \InvalidArgumentException('Either bill file or bill pending reason is required.');
        }

        $status = ExpenseStatus::from($data['status'] ?? 'PAID');
        $mode = PaymentMode::from($data['payment_mode']);
        $amount = (float) $data['amount'];

        $expense = DB::transaction(function () use ($festivalId, $data, $userId, $billUrl, $status, $mode, $amount) {
            if ($status === ExpenseStatus::PAID) {
                $this->assertBucketCovers($festivalId, $mode, $amount);
            }

            $expense = ExpenseEntry::create([
                'festival_id' => $festivalId,
                'title' => $data['title'],
                'category' => $data['category'],
                'amount' => $amount,
                'payment_mode' => $mode,
                'paid_to' => $data['paid_to'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'status' => $status,
                'bill_url' => $billUrl,
                'bill_pending_reason' => $data['bill_pending_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $userId,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            if ($status === ExpenseStatus::PAID) {
                $this->createMoneyTrailForExpense($expense);
                $this->debitBucket($festivalId, $mode, $amount);
            }

            return $expense;
        });

        $this->notifications->notifyExpenseCreated($expense);

        return $expense;
    }

    /**
     * Attach or replace a bill file for an expense.
     */
    public function attachBill(string $expenseId, $file): ExpenseEntry
    {
        $expense = ExpenseEntry::findOrFail($expenseId);
        $expense->bill_url = $this->storeBill($expense->festival_id, $file);
        $expense->save();

        return $expense;
    }

    /**
     * Mark an expense as paid and record it in the money trail + balance ledger.
     */
    public function markPaid(string $expenseId): ExpenseEntry
    {
        return DB::transaction(function () use ($expenseId) {
            $expense = ExpenseEntry::lockForUpdate()->findOrFail($expenseId);

            if ($expense->status === ExpenseStatus::PAID) {
                return $expense;
            }

            $this->assertBucketCovers(
                $expense->festival_id,
                $expense->payment_mode,
                (float) $expense->amount
            );

            $expense->status = ExpenseStatus::PAID;
            $expense->save();

            $this->createMoneyTrailForExpense($expense);
            $this->debitBucket(
                $expense->festival_id,
                $expense->payment_mode,
                (float) $expense->amount
            );

            return $expense;
        });
    }

    /**
     * Re-sync the ledger after a PAID expense's amount or payment mode is
     * edited: refund the original debit, re-debit the revised terms, and
     * record the movements in the money trail. Throws before any mutation
     * if the destination bucket cannot cover the revised amount.
     */
    public function adjustPaidExpense(ExpenseEntry $expense, float $oldAmount, PaymentMode $oldMode): ExpenseEntry
    {
        return DB::transaction(function () use ($expense, $oldAmount, $oldMode) {
            $expense = ExpenseEntry::lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== ExpenseStatus::PAID) {
                return $expense;
            }

            $newAmount = (float) $expense->amount;
            $newMode = $expense->payment_mode;

            if ($newAmount === $oldAmount && $newMode === $oldMode) {
                return $expense;
            }

            $this->creditBucket($expense->festival_id, $oldMode, $oldAmount);
            $this->assertBucketCovers($expense->festival_id, $newMode, $newAmount);
            $this->debitBucket($expense->festival_id, $newMode, $newAmount);

            if ($newMode !== $oldMode) {
                $this->createTrailAdjustment($expense, $oldMode, $oldAmount, true, 'mode revised to '.$newMode->value);
                $this->createTrailAdjustment($expense, $newMode, $newAmount, false, 'paid via '.$newMode->value);
            } else {
                $delta = round($newAmount - $oldAmount, 2);
                $this->createTrailAdjustment(
                    $expense,
                    $newMode,
                    abs($delta),
                    $delta < 0,
                    'amount revised '.$oldAmount.' to '.$newAmount
                );
            }

            return $expense;
        });
    }

    protected function storeBill(string $festivalId, $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'pdf';
        $filename = Str::uuid().'.'.$ext;
        $path = "bills/{$festivalId}/{$filename}";

        Storage::disk('local')->put($path, file_get_contents($file));

        return $path;
    }

    protected function createMoneyTrailForExpense(ExpenseEntry $expense): void
    {
        $type = match ($expense->payment_mode) {
            PaymentMode::CASH => MoneyTrailType::CASH_EXPENSE,
            PaymentMode::UPI => MoneyTrailType::UPI_EXPENSE,
            PaymentMode::CHEQUE, PaymentMode::NET_BANKING => MoneyTrailType::BANK_WITHDRAWAL,
        };

        MoneyTrailEntry::create([
            'festival_id' => $expense->festival_id,
            'type' => $type,
            'title' => $expense->title,
            'subtitle' => $expense->paid_to,
            'amount' => $expense->amount,
            'is_positive' => false,
            'reference_id' => $expense->id,
            'reference_type' => ExpenseEntry::class,
        ]);
    }

    protected function creditBucket(string $festivalId, PaymentMode $mode, float $amount): void
    {
        $balance = $this->lockedBalance($festivalId);
        $balance->addToBucket($this->bucketForMode($mode), $amount);
    }

    protected function createTrailAdjustment(ExpenseEntry $expense, PaymentMode $mode, float $amount, bool $isPositive, string $note): void
    {
        $type = match ($mode) {
            PaymentMode::CASH => MoneyTrailType::CASH_EXPENSE,
            PaymentMode::UPI => MoneyTrailType::UPI_EXPENSE,
            PaymentMode::CHEQUE, PaymentMode::NET_BANKING => MoneyTrailType::BANK_WITHDRAWAL,
        };

        MoneyTrailEntry::create([
            'festival_id' => $expense->festival_id,
            'type' => $type,
            'title' => $expense->title.' ('.$note.')',
            'subtitle' => $expense->paid_to,
            'amount' => $amount,
            'is_positive' => $isPositive,
            'reference_id' => $expense->id,
            'reference_type' => ExpenseEntry::class,
        ]);
    }

    protected function bucketForMode(PaymentMode $mode): string
    {
        return match ($mode) {
            PaymentMode::CASH => 'cash_treasurer',
            PaymentMode::UPI => 'upi',
            PaymentMode::CHEQUE, PaymentMode::NET_BANKING => 'bank',
        };
    }

    protected function lockedBalance(string $festivalId): FestivalBalance
    {
        $balance = FestivalBalance::lockForUpdate()
            ->where('festival_id', $festivalId)
            ->first();

        if ($balance) {
            return $balance;
        }

        try {
            return FestivalBalance::create([
                'festival_id' => $festivalId,
                'cash_treasurer' => 0,
                'cash_collectors' => 0,
                'bank' => 0,
                'upi' => 0,
                'version' => 0,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return FestivalBalance::lockForUpdate()
                ->where('festival_id', $festivalId)
                ->firstOrFail();
        }
    }

    protected function assertBucketCovers(string $festivalId, PaymentMode $mode, float $amount): void
    {
        $bucket = $this->bucketForMode($mode);
        $balance = $this->lockedBalance($festivalId);
        $available = (float) $balance->{$bucket};

        if ($available < $amount) {
            throw new \InvalidArgumentException(
                'Insufficient balance in the '.str_replace('_', ' ', $bucket).' bucket.'
            );
        }
    }

    protected function debitBucket(string $festivalId, PaymentMode $mode, float $amount): void
    {
        $bucket = $this->bucketForMode($mode);
        $balance = $this->lockedBalance($festivalId);
        $balance->addToBucket($bucket, -$amount);
    }
}
