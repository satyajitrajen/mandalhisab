<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CashHandover;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\FundTransfer;
use App\Models\MandalMember;
use App\Models\OtherIncome;
use App\Models\VarganiEntry;
use App\Services\ExpenseService;
use App\Services\VarganiService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController
{
    use ApiResponse;

    public function __construct(
        protected VarganiService $varganiService,
        protected ExpenseService $expenseService
    ) {}

    /**
     * Batch push changes from offline clients.
     *
     * Middleware: auth:sanctum (or jwt.auth)
     * Headers: X-Mandal-Id, X-Festival-Id
     */
    public function batchPush(Request $request): JsonResponse
    {
        $mandalId = $request->header('X-Mandal-Id');
        $festivalId = $request->header('X-Festival-Id');

        // Header ids are client-controlled; without this guard an offline
        // client could push records into another mandal's festival.
        $guard = $this->checkFestivalMembership($festivalId);
        if ($guard) {
            return $guard;
        }

        $validated = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($validated['payload'] as $index => $item) {
            $type = $item['type'] ?? null;
            $action = $item['action'] ?? null;
            $data = $item['data'] ?? [];
            $clientUuid = $item['clientUuid'] ?? null;

            try {
                if (! in_array($type, ['vargani', 'expense']) || ! in_array($action, ['create', 'update'])) {
                    throw new \InvalidArgumentException('Unsupported type or action');
                }

                if ($action === 'create') {
                    $result = match ($type) {
                        'vargani' => $this->processVarganiCreate($festivalId, $mandalId, $data, $clientUuid),
                        'expense' => $this->processExpenseCreate($festivalId, $data, $clientUuid),
                        default => throw new \InvalidArgumentException('Unsupported type'),
                    };
                } else {
                    throw new \InvalidArgumentException('Offline update is not supported. Re-open the record online to edit.');
                }

                $results[] = [
                    'index' => $index,
                    'clientUuid' => $clientUuid,
                    'type' => $type,
                    'action' => $action,
                    'status' => $result['status'],
                    'serverId' => $result['serverId'] ?? null,
                    'message' => $result['message'] ?? null,
                ];

                if ($result['status'] === 'success') {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'index' => $index,
                    'clientUuid' => $clientUuid,
                    'type' => $type,
                    'action' => $action,
                    'status' => 'error',
                    'serverId' => null,
                    'message' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        $processed = count($validated['payload']);

        return $this->success([
            'summary' => [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
            ],
            'results' => $results,
        ], 'Batch push processed');
    }

    /**
     * Pull delta since last sync.
     *
     * Middleware: auth:sanctum (or jwt.auth)
     * Headers: X-Festival-Id
     */
    public function pullDelta(Request $request): JsonResponse
    {
        $festivalId = $request->header('X-Festival-Id');

        $guard = $this->checkFestivalMembership($festivalId);
        if ($guard) {
            return $guard;
        }

        $validated = $request->validate([
            'lastSyncAt' => ['required', 'date'],
        ]);

        $lastSyncAt = $validated['lastSyncAt'];

        $vargani = VarganiEntry::where('festival_id', $festivalId)
            ->where('updated_at', '>', $lastSyncAt)
            ->get()
            ->map(fn ($m) => $this->mapVargani($m));

        $expenses = ExpenseEntry::where('festival_id', $festivalId)
            ->where('updated_at', '>', $lastSyncAt)
            ->get()
            ->map(fn ($m) => $this->mapExpense($m));

        $cashHandovers = CashHandover::where('festival_id', $festivalId)
            ->where('updated_at', '>', $lastSyncAt)
            ->get()
            ->map(fn ($m) => $this->mapCashHandover($m));

        $otherIncome = OtherIncome::where('festival_id', $festivalId)
            ->where('updated_at', '>', $lastSyncAt)
            ->get()
            ->map(fn ($m) => $this->mapOtherIncome($m));

        $fundTransfers = FundTransfer::where('festival_id', $festivalId)
            ->where('updated_at', '>', $lastSyncAt)
            ->get()
            ->map(fn ($m) => $this->mapFundTransfer($m));

        return $this->success([
            'syncToken' => now()->toIso8601String(),
            'vargani' => $vargani,
            'expenses' => $expenses,
            'cashHandovers' => $cashHandovers,
            'otherIncome' => $otherIncome,
            'fundTransfers' => $fundTransfers,
        ], 'Delta pulled');
    }

    protected function processVarganiCreate(?string $festivalId, ?string $mandalId, array $data, ?string $clientUuid): array
    {
        if (empty($data['donorName']) || empty($data['amount']) || empty($data['paymentMode']) || empty($data['area']) || empty($data['receiptType'])) {
            return [
                'status' => 'error',
                'serverId' => null,
                'message' => 'Missing required fields for vargani',
            ];
        }

        if ($clientUuid) {
            $existing = VarganiEntry::where('festival_id', $festivalId)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing) {
                return [
                    'status' => 'success',
                    'serverId' => $existing->id,
                    'message' => 'Duplicate clientUuid; existing record returned',
                ];
            }
        }

        $paymentMode = match (strtoupper((string) ($data['paymentMode'] ?? ''))) {
            'CASH' => 'CASH',
            'UPI' => 'UPI',
            'CHEQUE' => 'CHEQUE',
            'NET_BANKING', 'BANK' => 'NET_BANKING',
            default => 'CASH',
        };
        $receiptType = match (strtoupper((string) ($data['receiptType'] ?? ''))) {
            'DIGITAL' => 'DIGITAL',
            'PHYSICAL_BOOK', 'MANUAL' => 'PHYSICAL_BOOK',
            default => 'DIGITAL',
        };

        $mobileNumber = null;
        if (! empty($data['mobileNumber'])) {
            $digits = preg_replace('/\D/', '', (string) $data['mobileNumber']);
            $mobileNumber = (strlen($digits) >= 10) ? substr($digits, -10) : $digits;
        }

        $collectorId = $data['collectorId'] ?? null;
        if (empty($collectorId) && ! empty($data['collectorName'])) {
            // Mirror the direct API: resolve display names against this
            // mandal's active members instead of silently attributing to
            // the caller.
            $collectorId = MandalMember::where('mandal_id', Festival::where('id', $festivalId)->value('mandal_id'))
                ->where('is_active', true)
                ->whereHas('user', fn ($q) => $q->where('full_name', $data['collectorName']))
                ->value('user_id');

            if (empty($collectorId)) {
                return [
                    'status' => 'error',
                    'serverId' => null,
                    'message' => 'Collector must be an active member of this mandal',
                ];
            }
        }

        if (! empty($collectorId)) {
            $collectorIsMember = MandalMember::where('mandal_id', Festival::where('id', $festivalId)->value('mandal_id'))
                ->where('user_id', $collectorId)
                ->where('is_active', true)
                ->exists();

            if (! $collectorIsMember) {
                return [
                    'status' => 'error',
                    'serverId' => null,
                    'message' => 'Collector must be an active member of this mandal',
                ];
            }
        }

        $collectorId = $collectorId ?? auth()->id();

        try {
            $entry = $this->varganiService->createVargani($festivalId, [
                'donor_name' => $data['donorName'],
                'mobile_number' => $mobileNumber,
                'amount' => $data['amount'],
                'payment_mode' => $paymentMode,
                'area' => $data['area'],
                'address' => $data['address'] ?? null,
                'receipt_type' => $receiptType,
                'receipt_book_id' => $data['receiptBookId'] ?? null,
                'notes' => $data['notes'] ?? null,
                'client_uuid' => $clientUuid,
            ], $collectorId);

            return [
                'status' => 'success',
                'serverId' => $entry->id,
                'message' => 'Vargani created',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'serverId' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function processExpenseCreate(?string $festivalId, array $data, ?string $clientUuid): array
    {
        if (empty($data['title']) || empty($data['amount']) || empty($data['paymentMode']) || empty($data['paidTo']) || empty($data['date']) || empty($data['status']) || empty($data['category'])) {
            return [
                'status' => 'error',
                'serverId' => null,
                'message' => 'Missing required fields for expense',
            ];
        }

        // Expense writes are restricted to ADMIN/TREASURER (RBAC matrix).
        $mandalId = Festival::find($festivalId)?->mandal_id;
        $role = MandalMember::where('mandal_id', $mandalId)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->value('role');
        $roleValue = $role instanceof \BackedEnum ? $role->value : (string) $role;

        if (! in_array($roleValue, ['ADMIN', 'SUPER_ADMIN', 'TREASURER'], true)) {
            return [
                'status' => 'error',
                'serverId' => null,
                'message' => 'Only ADMIN or TREASURER can create expenses',
            ];
        }

        if ($clientUuid) {
            $existing = ExpenseEntry::where('festival_id', $festivalId)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing) {
                return [
                    'status' => 'success',
                    'serverId' => $existing->id,
                    'message' => 'Duplicate clientUuid; existing record returned',
                ];
            }
        }

        $category = match (strtoupper((string) ($data['category'] ?? ''))) {
            'STAGE_MANDAP', 'STAGE & MANDAP' => 'STAGE_MANDAP',
            'SOUND_LIGHTING', 'SOUND & LIGHTING' => 'SOUND_LIGHTING',
            'MURTI_DECORATION', 'MURTI & DECORATION' => 'MURTI_DECORATION',
            'POOJA_PRASAD', 'POOJA & PRASAD' => 'POOJA_PRASAD',
            'SECURITY_LOGISTICS', 'SECURITY & LOGISTICS' => 'SECURITY_LOGISTICS',
            default => 'MISCELLANEOUS',
        };
        $paymentMode = match (strtoupper((string) ($data['paymentMode'] ?? ''))) {
            'CASH' => 'CASH',
            'UPI' => 'UPI',
            'CHEQUE' => 'CHEQUE',
            'NET_BANKING', 'BANK' => 'NET_BANKING',
            default => 'CASH',
        };
        $status = match (strtoupper((string) ($data['status'] ?? ''))) {
            'PAID' => 'PAID',
            'PENDING' => 'PENDING',
            default => 'PENDING',
        };

        try {
            $entry = $this->expenseService->createExpense($festivalId, [
                'title' => $data['title'],
                'category' => $category,
                'amount' => $data['amount'],
                'payment_mode' => $paymentMode,
                'paid_to' => $data['paidTo'],
                'date' => $data['date'],
                'status' => $status,
                'bill_url' => $data['billUrl'] ?? null,
                'bill_pending_reason' => $data['billPendingReason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'client_uuid' => $clientUuid,
            ], auth()->id());

            return [
                'status' => 'success',
                'serverId' => $entry->id,
                'message' => 'Expense created',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'serverId' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function enumVal(mixed $enum): ?string
    {
        if ($enum instanceof \BackedEnum) {
            return $enum->value;
        }

        return is_string($enum) ? $enum : null;
    }

    protected function mapVargani(VarganiEntry $m): array
    {
        return [
            'id' => $m->id,
            'receiptNumber' => $m->receipt_number,
            'donorName' => $m->donor_name,
            'mobileNumber' => $m->mobile_number,
            'amount' => (float) $m->amount,
            'paymentMode' => $this->enumVal($m->payment_mode),
            'area' => $m->area,
            'address' => $m->address,
            'collectorId' => $m->collector_id,
            'receiptType' => $this->enumVal($m->receipt_type),
            'receiptBookId' => $m->receipt_book_id,
            'notes' => $m->notes,
            'isCancelled' => $m->is_cancelled,
            'clientUuid' => $m->client_uuid,
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    protected function mapExpense(ExpenseEntry $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'category' => $this->enumVal($m->category),
            'amount' => (float) $m->amount,
            'paymentMode' => $this->enumVal($m->payment_mode),
            'paidTo' => $m->paid_to,
            'date' => $m->date?->format('Y-m-d'),
            'status' => $this->enumVal($m->status),
            'billUrl' => $m->bill_url,
            'billPendingReason' => $m->bill_pending_reason,
            'notes' => $m->notes,
            'clientUuid' => $m->client_uuid,
            'createdByUserId' => $m->created_by_user_id,
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    protected function mapCashHandover(CashHandover $m): array
    {
        return [
            'id' => $m->id,
            'fromUserId' => $m->from_user_id,
            'toUserId' => $m->to_user_id,
            'amount' => (float) $m->amount,
            'linkedEntryIds' => $m->linked_entry_ids,
            'linkedEntriesCount' => $m->linked_entries_count,
            'linkedDateRange' => $m->linked_date_range,
            'notes' => $m->notes,
            'photoUrl' => $m->photo_url,
            'status' => $this->enumVal($m->status),
            'authMethod' => $this->enumVal($m->auth_method),
            'verificationNotes' => $m->verification_notes,
            'verifiedAt' => $m->verified_at?->toIso8601String(),
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    protected function mapOtherIncome(OtherIncome $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'amount' => (float) $m->amount,
            'date' => $m->date?->format('Y-m-d'),
            'notes' => $m->notes,
            'createdByUserId' => $m->created_by_user_id,
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    protected function mapFundTransfer(FundTransfer $m): array
    {
        return [
            'id' => $m->id,
            'fromBucket' => $this->enumVal($m->from_bucket),
            'toBucket' => $this->enumVal($m->to_bucket),
            'bankAccountId' => $m->bank_account_id,
            'amount' => (float) $m->amount,
            'notes' => $m->notes,
            'createdByUserId' => $m->created_by_user_id,
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The caller must be an active member of the festival's mandal.
     * Returns the error response, or null when the guard passes.
     */
    protected function checkFestivalMembership(?string $festivalId): ?JsonResponse
    {
        if (empty($festivalId)) {
            return $this->error('TENANT_REQUIRED', 'X-Festival-Id header is required', 400);
        }

        $isMember = MandalMember::where('user_id', auth()->id())
            ->where('is_active', true)
            ->whereIn('mandal_id', Festival::where('id', $festivalId)->pluck('mandal_id'))
            ->exists();

        if (! $isMember) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        return null;
    }
}
