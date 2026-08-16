<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\MoneyTrailType;
use App\Models\BankAccount;
use App\Models\CashHandover;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\MandalMember;
use App\Models\MoneyTrailEntry;
use App\Models\OtherIncome;
use App\Models\VarganiEntry;
use App\Services\CacheKeyService;
use App\Services\FundService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FundController
{
    use ApiResponse;

    public function __construct(protected FundService $fundService)
    {
    }

    protected function checkTreasurerOrAdmin(Festival $festival): void
    {
        $membership = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            abort(response()->json([
                'success' => false, 'statusCode' => 403,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You are not a member of this mandal'],
            ], 403));
        }

        $allowed = [MemberRole::ADMIN, MemberRole::SUPER_ADMIN, MemberRole::TREASURER];
        if (! in_array($membership->role, $allowed)) {
            abort(response()->json([
                'success' => false, 'statusCode' => 403,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Only ADMIN or TREASURER can access fund data'],
            ], 403));
        }
    }

    protected function checkMember(Festival $festival): void
    {
        $membership = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            abort(response()->json([
                'success' => false, 'statusCode' => 403,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You are not a member of this mandal'],
            ], 403));
        }
    }

    /**
     * GET /api/v1/festivals/:festivalId/funds/summary
     */
    public function summary($festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $cacheKey = CacheKeyService::fundsSummary($festival);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_FUNDS_SUMMARY, function () use ($festival, $festivalModel) {
            $summary = $this->fundService->getSummary($festival);
            $buckets = $summary['buckets'];

            // Backward-compat: compute total expenses from DB for UI display
            $totalExpenses = (float) $festivalModel->expenseEntries()
                ->where('status', 'PAID')
                ->sum('amount');

            $bankAccounts = $festivalModel->bankAccounts()
                ->where('is_active', true)
                ->get()
                ->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'bankName' => $a->bank_name,
                        'balance' => (float) $a->balance,
                    ];
                });

            return $this->success([
                'totalCollected' => $buckets['cash_treasurer'] + $buckets['cash_collectors'] + $buckets['bank'] + $buckets['upi'] + $totalExpenses,
                'totalExpenses' => $totalExpenses,
                'netBalance' => $buckets['cash_treasurer'] + $buckets['cash_collectors'] + $buckets['bank'] + $buckets['upi'],
                'cashInHand' => max(0, $buckets['cash_treasurer'] + $buckets['cash_collectors']),
                'cashTreasurer' => $buckets['cash_treasurer'],
                'cashCollectors' => $buckets['cash_collectors'],
                'upiBalance' => $buckets['upi'],
                'bankBalance' => $buckets['bank'],
                'bankAccounts' => $bankAccounts,
            ], 'Fund summary retrieved');
        });
    }

    /**
     * GET /api/v1/festivals/:festivalId/funds/money-trail
     */
    public function moneyTrail(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $validated = $request->validate([
            'type' => ['nullable', 'in:CASH_RECEIVED,UPI_RECEIVED,BANK_DEPOSIT,BANK_WITHDRAWAL,CASH_EXPENSE,UPI_EXPENSE,CASH_HANDOVER,FUND_TRANSFER,OTHER_INCOME'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'type' => $validated['type'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::fundsTrail($festival, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_FUNDS_TRAIL, function () use ($festival, $validated) {
            $entries = $this->fundService->getMoneyTrail($festival, array_filter([
                'type' => $validated['type'] ?? null,
            ]));

            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;
            $paginated = $entries->forPage($page, $limit)->values();

            $data = $paginated->map(function ($e) {
                return [
                    'id' => $e->id,
                    'type' => $e->type->value,
                    'title' => $e->title,
                    'subtitle' => $e->subtitle,
                    'amount' => (float) $e->amount,
                    'isPositive' => $e->is_positive,
                    'referenceId' => $e->reference_id,
                    'referenceType' => $e->reference_type,
                    'createdAt' => $e->created_at?->toIso8601String(),
                ];
            });

            return $this->success($data, 'Money trail retrieved', 200, [
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $limit,
                    'total' => $entries->count(),
                    'lastPage' => (int) ceil($entries->count() / $limit),
                ],
            ]);
        });
    }

    /**
     * GET /api/v1/festivals/:festivalId/funds/handovers
     */
    public function indexHandovers(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $validated = $request->validate([
            'status' => ['nullable', 'in:PENDING_APPROVAL,VERIFIED_ACCEPTED,REJECTED'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'status' => $validated['status'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::fundsHandovers($festival, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_FUNDS_HANDOVERS, function () use ($festival, $validated) {
            $query = CashHandover::where('festival_id', $festival);

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;
            $handovers = $query->orderBy('created_at', 'desc')->paginate($limit, ['*'], 'page', $page);

            $data = $handovers->map(function ($h) {
                return [
                    'id' => $h->id,
                    'fromUser' => $h->fromUser?->full_name,
                    'fromUserId' => $h->from_user_id,
                    'toUser' => $h->toUser?->full_name,
                    'toUserId' => $h->to_user_id,
                    'amount' => (float) $h->amount,
                    'linkedEntriesCount' => $h->linked_entries_count,
                    'linkedDateRange' => $h->linked_date_range,
                    'status' => $h->status->value,
                    'notes' => $h->notes,
                    'photoUrl' => $h->photo_url,
                    'verifiedAt' => $h->verified_at?->toIso8601String(),
                    'createdAt' => $h->created_at?->toIso8601String(),
                ];
            });

            return $this->success($data, 'Handover list retrieved', 200, [
                'meta' => [
                    'currentPage' => $handovers->currentPage(),
                    'perPage' => $handovers->perPage(),
                    'total' => $handovers->total(),
                    'lastPage' => $handovers->lastPage(),
                ],
            ]);
        });
    }

    /**
     * POST /api/v1/festivals/:festivalId/funds/handovers
     */
    public function storeHandover(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMember($festivalModel);

        $validated = $request->validate([
            'collectorId' => ['nullable', 'string'],
            'fromUserId' => ['nullable', 'string'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'fromRole' => ['nullable', 'string', 'max:50'],
            'toUserId' => ['nullable', 'string'],
            'toName' => ['nullable', 'string', 'max:120'],
            'toRole' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'linkedEntryIds' => ['nullable', 'array'],
            'linkedEntryIds.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:500'],
            'photoBase64' => ['nullable', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $fromUserId = $validated['fromUserId'] ?? $validated['collectorId'] ?? auth()->id();
        $toUserId = $validated['toUserId'] ?? null;

        // Calculate linked entries info
        $linkedIds = $validated['linkedEntryIds'] ?? [];
        $linkedCount = count($linkedIds);
        $linkedDateRange = null;

        if ($linkedCount > 0) {
            $entries = VarganiEntry::where('festival_id', $festival)
                ->whereIn('id', $linkedIds)
                ->where('is_cancelled', false)
                ->orderBy('created_at')
                ->get();

            if ($entries->isNotEmpty()) {
                $first = $entries->first()->created_at?->format('Y-m-d');
                $last = $entries->last()->created_at?->format('Y-m-d');
                $linkedDateRange = $first === $last ? $first : "$first to $last";
            }
        }

        $photoUrl = null;
        if ($request->hasFile('photoFile') || $request->hasFile('photo')) {
            $file = $request->file('photoFile') ?? $request->file('photo');
            $path = $file->store('handovers', 'public');
            $photoUrl = asset('storage/' . $path);
        } elseif (! empty($validated['photoBase64'])) {
            $data = base64_decode(explode(',', $validated['photoBase64'])[1] ?? $validated['photoBase64']);
            $path = 'handovers/' . uniqid() . '.jpg';
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $data);
            $photoUrl = asset('storage/' . $path);
        }

        try {
            $handover = $this->fundService->createHandover($festival, [
                'to_user_id' => $toUserId,
                'amount' => $validated['amount'],
                'linked_entry_ids' => $linkedIds,
                'linked_entries_count' => $linkedCount,
                'linked_date_range' => $linkedDateRange,
                'notes' => $validated['notes'] ?? null,
                'photo_url' => $photoUrl,
            ], $fromUserId);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        CacheKeyService::clearFunds($festival);

        $statusStr = $handover->status instanceof \BackedEnum ? $handover->status->value : (string) $handover->status;

        return $this->success([
            'id' => $handover->id,
            'fromName' => $handover->fromUser?->full_name ?? ($validated['fromName'] ?? 'Collector'),
            'fromRole' => $validated['fromRole'] ?? 'Collector',
            'toName' => $handover->toUser?->full_name ?? ($validated['toName'] ?? 'Treasurer'),
            'toRole' => $validated['toRole'] ?? 'Treasurer',
            'amount' => (float) $handover->amount,
            'status' => $statusStr,
            'linkedEntriesCount' => $linkedCount,
            'linkedDateRange' => $linkedDateRange ?? 'Today',
            'notes' => $handover->notes,
            'photoUrl' => $photoUrl,
            'createdAt' => $handover->created_at?->toIso8601String(),
        ], 'Handover recorded successfully', 201);
    }

    /**
     * GET /api/v1/funds/handovers/:handoverId
     */
    public function showHandover($handover)
    {
        $handoverModel = CashHandover::find($handover);
        if (! $handoverModel) {
            return $this->error('NOT_FOUND', 'Handover not found', 404);
        }

        $this->checkTreasurerOrAdmin($handoverModel->festival);

        $statusStr = $handoverModel->status instanceof \BackedEnum ? $handoverModel->status->value : (string) $handoverModel->status;

        return $this->success([
            'id' => $handoverModel->id,
            'fromName' => $handoverModel->fromUser?->full_name ?? 'Collector',
            'fromRole' => 'Collector',
            'fromUser' => $handoverModel->fromUser?->full_name,
            'fromUserId' => $handoverModel->from_user_id,
            'toName' => $handoverModel->toUser?->full_name ?? 'Treasurer',
            'toRole' => 'Treasurer',
            'toUser' => $handoverModel->toUser?->full_name,
            'toUserId' => $handoverModel->to_user_id,
            'amount' => (float) $handoverModel->amount,
            'linkedEntryIds' => $handoverModel->linked_entry_ids ?? [],
            'linkedEntriesCount' => $handoverModel->linked_entries_count ?? 0,
            'linkedDateRange' => $handoverModel->linked_date_range ?? 'Today',
            'status' => $statusStr,
            'notes' => $handoverModel->notes,
            'photoUrl' => $handoverModel->photo_url,
            'verificationNotes' => $handoverModel->verification_notes,
            'verifiedAt' => $handoverModel->verified_at?->toIso8601String(),
            'createdAt' => $handoverModel->created_at?->toIso8601String(),
        ], 'Handover details retrieved');
    }

    /**
     * POST /api/v1/funds/handovers/:handoverId/verify
     */
    public function verifyHandover(Request $request, $handover)
    {
        $handoverModel = CashHandover::find($handover);
        if (! $handoverModel) {
            return $this->error('NOT_FOUND', 'Handover not found', 404);
        }

        $this->checkTreasurerOrAdmin($handoverModel->festival);

        $validated = $request->validate([
            'status' => ['required', 'in:VERIFIED_ACCEPTED,REJECTED,accept,reject'],
            'pin' => ['nullable', 'string', 'digits:4'],
            'authMethod' => ['nullable', 'string', 'in:PIN,BIOMETRIC'],
            'verificationNotes' => ['nullable', 'string'],
        ]);

        $authMethod = $validated['authMethod'] ?? 'PIN';

        if ($authMethod === 'PIN') {
            $user = auth()->user();
            if (empty($user->security_pin)) {
                return $this->error('PIN_NOT_SET', 'Set a security PIN in Profile settings before verifying handovers', 422);
            }

            if (empty($validated['pin'])) {
                return $this->error('PIN_REQUIRED', 'Security PIN is required to verify handovers', 422);
            }

            if (! \Illuminate\Support\Facades\Hash::check($validated['pin'], $user->security_pin)) {
                return $this->error('INVALID_PIN', 'The entered PIN is incorrect', 422);
            }
        }

        $action = match ($validated['status']) {
            'VERIFIED_ACCEPTED', 'accept' => 'accept',
            'REJECTED', 'reject' => 'reject',
        };

        try {
            $handoverModel = $this->fundService->verifyHandover(
                $handover,
                $action,
                $authMethod,
                $validated['verificationNotes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        CacheKeyService::clearFunds($handoverModel->festival_id);

        return $this->success([
            'id' => $handoverModel->id,
            'status' => $handoverModel->status->value,
            'verifiedAt' => $handoverModel->verified_at?->toIso8601String(),
        ], 'Handover verified successfully');
    }

    /**
     * GET /api/v1/festivals/:festivalId/funds/bank-accounts
     */
    public function indexBankAccounts($festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $accounts = $festivalModel->bankAccounts()
            ->where('is_active', true)
            ->get()
            ->map(function ($a) {
                return [
                    'id' => $a->id,
                    'bankName' => $a->bank_name,
                    'accountNumberMasked' => '****' . substr($a->account_number, -4),
                    'ifsc' => $a->ifsc,
                    'accountType' => $a->account_type->value,
                    'balance' => (float) $a->balance,
                    'upiId' => $a->upi_id,
                    'isActive' => $a->is_active,
                ];
            });

        return $this->success($accounts, 'Bank account list retrieved');
    }

    /**
     * POST /api/v1/festivals/:festivalId/funds/bank-accounts
     */
    public function storeBankAccount(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $validated = $request->validate([
            'bankName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:50'],
            'ifscCode' => ['required', 'string', 'max:20'],
            'accountType' => ['nullable', 'in:SAVINGS,CURRENT,FIXED_DEPOSIT'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'upiId' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $account = $this->fundService->createBankAccount($festival, [
                'bank_name' => $validated['bankName'],
                'account_number' => $validated['accountNumber'],
                'ifsc' => $validated['ifscCode'],
                'account_type' => $validated['accountType'] ?? 'SAVINGS',
                'balance' => $validated['balance'] ?? 0,
                'upi_id' => $validated['upiId'] ?? null,
                'is_active' => true,
            ]);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        CacheKeyService::clearDashboardAndFunds($festival);

        return $this->success([
            'id' => $account->id,
            'bankName' => $account->bank_name,
            'accountNumberMasked' => '****' . substr($account->account_number, -4),
            'ifsc' => $account->ifsc,
            'balance' => (float) $account->balance,
        ], 'Bank account created successfully', 201);
    }

    /**
     * PATCH /api/v1/funds/bank-accounts/:accountId
     */
    public function updateBankAccount(Request $request, $account)
    {
        $accountModel = BankAccount::find($account);
        if (! $accountModel) {
            return $this->error('NOT_FOUND', 'Bank account not found', 404);
        }

        $this->checkTreasurerOrAdmin($accountModel->festival);

        $validated = $request->validate([
            'bankName' => ['nullable', 'string', 'max:255'],
            'ifscCode' => ['nullable', 'string', 'max:20'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'upiId' => ['nullable', 'string', 'max:100'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        try {
            $account = $this->fundService->updateBankAccount($account, array_filter([
                'bank_name' => $validated['bankName'] ?? null,
                'ifsc' => $validated['ifscCode'] ?? null,
                'balance' => $validated['balance'] ?? null,
                'upi_id' => $validated['upiId'] ?? null,
                'is_active' => isset($validated['isActive']) ? $validated['isActive'] : null,
            ], fn ($v) => $v !== null));
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        CacheKeyService::clearDashboardAndFunds($accountModel->festival_id);

        return $this->success([
            'id' => $account->id,
            'bankName' => $account->bank_name,
            'balance' => (float) $account->balance,
            'isActive' => $account->is_active,
        ], 'Bank account updated successfully');
    }

    /**
     * POST /api/v1/festivals/:festivalId/funds/transfers
     */
    public function transfer(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $validated = $request->validate([
            'fromBucket' => ['required', 'in:CASH_TREASURER,CASH_COLLECTORS,BANK,UPI'],
            'toBucket' => ['required', 'in:CASH_TREASURER,CASH_COLLECTORS,BANK,UPI'],
            'bankAccountId' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:250'],
        ]);

        if ($validated['fromBucket'] === $validated['toBucket']) {
            return $this->error('VALIDATION_FAILED', 'From and to buckets must be different', 422);
        }

        try {
            $transfer = $this->fundService->transferFunds($festival, [
                'from_bucket' => $validated['fromBucket'],
                'to_bucket' => $validated['toBucket'],
                'bank_account_id' => $validated['bankAccountId'] ?? null,
                'amount' => $validated['amount'],
                'notes' => $validated['notes'] ?? null,
            ], auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        CacheKeyService::clearFunds($festival);

        return $this->success([
            'fromBucket' => $validated['fromBucket'],
            'toBucket' => $validated['toBucket'],
            'amount' => (float) $validated['amount'],
        ], 'Transfer completed successfully');
    }

    /**
     * GET /api/v1/festivals/:festivalId/other-income
     */
    public function indexOtherIncome($festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $income = $festivalModel->otherIncomes()
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($i) {
                $dateStr = $i->date instanceof \DateTimeInterface ? $i->date->format('Y-m-d') : (string) $i->date;
                return [
                    'id' => $i->id,
                    'title' => $i->title,
                    'amount' => (float) $i->amount,
                    'date' => $dateStr,
                    'notes' => $i->notes,
                    'createdBy' => $i->createdBy?->full_name,
                    'createdAt' => $i->created_at?->toIso8601String(),
                ];
            });

        return $this->success($income, 'Other income list retrieved');
    }

    /**
     * POST /api/v1/festivals/:festivalId/other-income
     */
    public function storeOtherIncome(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkTreasurerOrAdmin($festivalModel);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $income = OtherIncome::create([
            'festival_id' => $festival,
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'date' => $validated['date'],
            'notes' => $validated['notes'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);

        // Update balance ledger: other income defaults to cash_treasurer
        $balance = FestivalBalance::forFestival($festival);
        $balance->addToBucket('cash_treasurer', (float) $validated['amount']);

        MoneyTrailEntry::create([
            'festival_id' => $festival,
            'type' => MoneyTrailType::OTHER_INCOME,
            'title' => $validated['title'],
            'subtitle' => 'Other Income',
            'amount' => $validated['amount'],
            'is_positive' => true,
            'reference_id' => $income->id,
            'reference_type' => 'other_income',
        ]);

        CacheKeyService::clearFunds($festival);

        $dateStr = $income->date instanceof \DateTimeInterface ? $income->date->format('Y-m-d') : (string) $income->date;

        return $this->success([
            'id' => $income->id,
            'title' => $income->title,
            'amount' => (float) $income->amount,
            'date' => $dateStr,
        ], 'Other income recorded successfully', 201);
    }
}
