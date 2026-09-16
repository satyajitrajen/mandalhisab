<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthMethod;
use App\Enums\ExpenseStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Models\Festival;
use App\Models\FinalHisabAudit;
use App\Models\MandalMember;
use App\Models\User;
use App\Services\CacheKeyService;
use App\Services\FestivalFinancials;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;

class ReportController
{
    use ApiResponse;

    public function __construct(
        protected FestivalFinancials $financials,
        protected NotificationService $notifications,
    ) {}

    protected function resolveFestival($festivalId): Festival
    {
        return Festival::findOrFail($festivalId);
    }

    /**
     * Mandal / festival labels for client PDFs. Festival year is appended
     * only when the stored name does not already include it.
     *
     * @return array{mandalName: string, festivalName: string}
     */
    protected function hisabIdentity(Festival $festival): array
    {
        $festival->loadMissing('mandal');
        $name = trim((string) $festival->name);
        $year = $festival->year !== null ? (string) $festival->year : '';
        if ($year !== '' && $name !== '' && ! str_contains($name, $year)) {
            $name .= ' '.$year;
        }

        return [
            'mandalName' => $festival->mandal?->name ?? '',
            'festivalName' => $name !== '' ? $name : ($year !== '' ? $year : 'Festival'),
        ];
    }

    protected function checkAdminOrTreasurer(Festival $festival): void
    {
        $membership = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            abort(response()->json([
                'success' => false,
                'statusCode' => 403,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You are not a member of this mandal'],
            ], 403));
        }

        $allowed = [MemberRole::ADMIN, MemberRole::SUPER_ADMIN, MemberRole::TREASURER];
        if (! in_array($membership->role, $allowed, true)) {
            abort(response()->json([
                'success' => false,
                'statusCode' => 403,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Only ADMIN or TREASURER can access reports'],
            ], 403));
        }
    }

    /**
     * Overview report for a festival.
     *
     * Middleware: auth:sanctum, TenantScope, RequireRole:ADMIN|TREASURER
     */
    public function overview($festival)
    {
        $festivalModel = $this->resolveFestival($festival);
        $this->checkAdminOrTreasurer($festivalModel);

        $cacheKey = CacheKeyService::reportsOverview($festival);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_REPORTS_OVERVIEW, function () use ($festivalModel) {
            $totals = $this->financials->forFestival($festivalModel);
            $varganiQuery = $festivalModel->varganiEntries()->where('is_cancelled', false);

            $totalVargani = $totals['vargani_total'];
            $totalExpenses = $totals['paid_expenses'];
            $totalOtherIncome = $totals['other_income_total'];
            $openingBalance = $totals['opening_balance'];
            $totalIncome = $totals['total_income'];
            $netBalance = $totals['closing_balance'];

            $totalDonors = $varganiQuery->clone()->count();
            $totalReceipts = $totalDonors;

            $totalCash = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::CASH)->sum('amount');
            $totalUPI = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::UPI)->sum('amount');
            $totalBank = (float) $varganiQuery->clone()->whereIn('payment_mode', [PaymentMode::CHEQUE, PaymentMode::NET_BANKING])->sum('amount');

            $expenseByCategory = $festivalModel->expenseEntries()
                ->where('status', ExpenseStatus::PAID)
                ->select('category', DB::raw('SUM(amount) as total'))
                ->groupBy('category')
                ->get()
                ->map(fn ($e) => [
                    'category' => $e->category instanceof \BackedEnum ? $e->category->value : (string) $e->category,
                    'total' => (float) $e->total,
                ]);

            $thirtyDaysAgo = now()->subDays(30)->startOfDay();

            $dailyCollection = $festivalModel->varganiEntries()
                ->where('is_cancelled', false)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'total' => (float) $row->total,
                ]);

            $dailyExpenses = $festivalModel->expenseEntries()
                ->where('status', ExpenseStatus::PAID)
                ->where('date', '>=', $thirtyDaysAgo->toDateString())
                ->select('date', DB::raw('SUM(amount) as total'))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'total' => (float) $row->total,
                ]);

            return $this->success([
                'totalVargani' => $totalVargani,
                'totalOtherIncome' => $totalOtherIncome,
                'totalIncome' => $totalIncome,
                'openingBalance' => $openingBalance,
                'totalExpenses' => $totalExpenses,
                'netBalance' => $netBalance,
                'closingBalance' => $netBalance,
                'totalDonors' => $totalDonors,
                'totalReceipts' => $totalReceipts,
                'totalCash' => $totalCash,
                'totalUPI' => $totalUPI,
                'totalBank' => $totalBank,
                'expenseByCategory' => $expenseByCategory,
                'dailyCollection' => $dailyCollection,
                'dailyExpenses' => $dailyExpenses,
            ], 'Overview report retrieved');
        });
    }

    /**
     * Typed report for a festival.
     *
     * Middleware: auth:sanctum, TenantScope, RequireRole:ADMIN|TREASURER
     */
    public function typedReport(Request $request, $festival, $reportType)
    {
        $festivalModel = $this->resolveFestival($festival);
        $this->checkAdminOrTreasurer($festivalModel);

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        $startDate = $validated['startDate'] ?? null;
        $endDate = $validated['endDate'] ?? null;

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'type' => $reportType,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]));
        $cacheKey = CacheKeyService::reportsTyped($festival, $reportType, $paramsHash);

        $normalized = match ($reportType) {
            'collections', 'vargani_summary' => 'vargani_summary',
            'expenses', 'expense_summary' => 'expense_summary',
            'cash', 'fund_summary' => 'fund_summary',
            'collectors', 'member_activity' => 'member_activity',
            'income-expense' => 'income_expense',
            'receipt-books' => 'receipt_books',
            default => null,
        };

        if ($normalized === null) {
            return $this->error('INVALID_REPORT_TYPE', 'Unsupported report type', 400);
        }

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_REPORTS_TYPED, function () use ($festivalModel, $normalized, $startDate, $endDate) {
            return match ($normalized) {
                'vargani_summary' => $this->varganiSummary($festivalModel, $startDate, $endDate),
                'expense_summary' => $this->expenseSummary($festivalModel, $startDate, $endDate),
                'fund_summary' => $this->fundSummary($festivalModel),
                'member_activity' => $this->memberActivity($festivalModel, $startDate, $endDate),
                'income_expense' => $this->incomeExpense($festivalModel, $startDate, $endDate),
                'receipt_books' => $this->receiptBooksReport($festivalModel),
                default => $this->error('INVALID_REPORT_TYPE', 'Unsupported report type', 400),
            };
        });
    }

    protected function varganiSummary(Festival $festival, ?string $startDate, ?string $endDate)
    {
        $query = $festival->varganiEntries()->where('is_cancelled', false);

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $byPaymentMode = $query->clone()
            ->select('payment_mode', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_mode')
            ->get()
            ->map(fn ($row) => [
                'paymentMode' => $row->payment_mode->value,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $byArea = $query->clone()
            ->select('area', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('area')
            ->get()
            ->map(fn ($row) => [
                'area' => $row->area,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $total = (float) $query->sum('amount');
        $rows = $byPaymentMode->map(fn ($row) => [
            'label' => $row['paymentMode'],
            'amount' => $row['total'],
            'pending' => $row['count'].' receipts',
        ])->values();

        return $this->success([
            'totalAmount' => $total,
            'byPaymentMode' => $byPaymentMode,
            'byArea' => $byArea,
            'rows' => $rows,
        ], 'Vargani summary retrieved');
    }

    protected function expenseSummary(Festival $festival, ?string $startDate, ?string $endDate)
    {
        $query = $festival->expenseEntries();

        if ($startDate) {
            $query->whereDate('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('date', '<=', $endDate);
        }

        $byCategory = $query->clone()
            ->select('category', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category->value,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $byStatus = $query->clone()
            ->select('status', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $total = (float) $query->sum('amount');
        $rows = $byCategory->map(fn ($row) => [
            'label' => $row['category'],
            'amount' => $row['total'],
            'pending' => $row['count'].' bills',
        ])->values();

        return $this->success([
            'totalAmount' => $total,
            'byCategory' => $byCategory,
            'byStatus' => $byStatus,
            'rows' => $rows,
        ], 'Expense summary retrieved');
    }

    protected function fundSummary(Festival $festival)
    {
        $varganiQuery = $festival->varganiEntries()->where('is_cancelled', false);
        $cashTotal = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::CASH)->sum('amount');
        $upiTotal = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::UPI)->sum('amount');
        $bankTotal = (float) $varganiQuery->clone()->whereIn('payment_mode', [PaymentMode::CHEQUE, PaymentMode::NET_BANKING])->sum('amount');

        $cashExpenses = (float) $festival->expenseEntries()
            ->where('status', ExpenseStatus::PAID)
            ->where('payment_mode', PaymentMode::CASH)
            ->sum('amount');

        $cashHandedOver = (float) $festival->cashHandovers()
            ->where('status', 'VERIFIED_ACCEPTED')
            ->sum('amount');

        $cashCollectors = max(0, $cashTotal - $cashHandedOver - $cashExpenses);
        $cashTreasurer = $cashHandedOver;

        $bankAccounts = $festival->bankAccounts()
            ->where('is_active', true)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'bankName' => $a->bank_name,
                'balance' => (float) $a->balance,
            ]);

        $buckets = [
            [
                'bucket' => 'CASH_TREASURER',
                'balance' => $cashTreasurer,
            ],
            [
                'bucket' => 'CASH_COLLECTORS',
                'balance' => $cashCollectors,
            ],
            [
                'bucket' => 'BANK',
                'balance' => $bankTotal,
                'accounts' => $bankAccounts,
            ],
            [
                'bucket' => 'UPI',
                'balance' => $upiTotal,
            ],
        ];

        $totalCollected = $cashTotal + $upiTotal + $bankTotal;
        $rows = collect($buckets)->map(fn ($bucket) => [
            'label' => $bucket['bucket'],
            'amount' => $bucket['balance'],
        ])->values();

        return $this->success([
            'buckets' => $buckets,
            'totalAmount' => $totalCollected,
            'totalCollected' => $totalCollected,
            'totalExpenses' => $cashExpenses + (float) $festival->expenseEntries()->where('status', ExpenseStatus::PAID)->whereIn('payment_mode', [PaymentMode::UPI, PaymentMode::CHEQUE, PaymentMode::NET_BANKING])->sum('amount'),
            'rows' => $rows,
        ], 'Fund summary retrieved');
    }

    protected function memberActivity(Festival $festival, ?string $startDate, ?string $endDate)
    {
        $members = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('is_active', true)
            ->with('user')
            ->get();

        $varganiQuery = $festival->varganiEntries()->where('is_cancelled', false);
        if ($startDate) {
            $varganiQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $varganiQuery->whereDate('created_at', '<=', $endDate);
        }

        $expenseQuery = $festival->expenseEntries();
        if ($startDate) {
            $expenseQuery->whereDate('date', '>=', $startDate);
        }
        if ($endDate) {
            $expenseQuery->whereDate('date', '<=', $endDate);
        }

        $activity = $members->map(function ($member) use ($varganiQuery, $expenseQuery) {
            $userId = $member->user_id;

            $varganiCount = $varganiQuery->clone()->where('collector_id', $userId)->count();
            $varganiSum = (float) $varganiQuery->clone()->where('collector_id', $userId)->sum('amount');

            $expenseCount = $expenseQuery->clone()->where('created_by_user_id', $userId)->count();
            $expenseSum = (float) $expenseQuery->clone()->where('created_by_user_id', $userId)->sum('amount');

            return [
                'memberId' => $member->id,
                'userId' => $userId,
                'name' => $member->user?->full_name,
                'role' => $member->role->value,
                'varganiCount' => $varganiCount,
                'varganiSum' => $varganiSum,
                'expenseCount' => $expenseCount,
                'expenseSum' => $expenseSum,
            ];
        });

        $rows = $activity->map(fn ($row) => [
            'label' => $row['name'] ?? 'Member',
            'amount' => $row['varganiSum'],
            'pending' => $row['role'].' · '.$row['varganiCount'].' receipts',
        ])->values();

        return $this->success([
            'totalAmount' => (float) $activity->sum('varganiSum'),
            'activity' => $activity,
            'rows' => $rows,
        ], 'Member activity retrieved');
    }

    protected function incomeExpense(Festival $festival, ?string $startDate, ?string $endDate)
    {
        $varganiQuery = $festival->varganiEntries()->where('is_cancelled', false);
        $expenseQuery = $festival->expenseEntries();
        $otherQuery = $festival->otherIncomes();

        if ($startDate) {
            $varganiQuery->whereDate('created_at', '>=', $startDate);
            $expenseQuery->whereDate('date', '>=', $startDate);
            $otherQuery->whereDate('date', '>=', $startDate);
        }
        if ($endDate) {
            $varganiQuery->whereDate('created_at', '<=', $endDate);
            $expenseQuery->whereDate('date', '<=', $endDate);
            $otherQuery->whereDate('date', '<=', $endDate);
        }

        $vargani = (float) $varganiQuery->sum('amount');
        $other = (float) $otherQuery->sum('amount');
        $expenses = (float) $expenseQuery->where('status', ExpenseStatus::PAID)->sum('amount');
        $income = $vargani + $other;

        return $this->success([
            'totalAmount' => $income,
            'totals' => [
                'vargani' => $vargani,
                'otherIncome' => $other,
                'income' => $income,
                'expenses' => $expenses,
                'net' => $income - $expenses,
            ],
            'rows' => [
                ['label' => 'Vargani', 'amount' => $vargani],
                ['label' => 'Other income', 'amount' => $other],
                ['label' => 'Expenses', 'amount' => $expenses],
                ['label' => 'Net', 'amount' => $income - $expenses],
            ],
        ], 'Income vs expense retrieved');
    }

    protected function receiptBooksReport(Festival $festival)
    {
        $books = $festival->receiptBooks()->with('assignedTo')->get();
        $rows = $books->map(function ($book) {
            $range = $book->end_number - $book->start_number + 1;
            $used = (int) $book->used_count;

            return [
                'label' => 'Book '.$book->book_number,
                'amount' => $used,
                'pending' => ($book->assignedTo?->full_name ?? 'Unassigned')
                    .' · '.($book->status?->value ?? ''),
                'used' => $used,
                'available' => max(0, $range - $used),
                'assignedTo' => $book->assignedTo?->full_name,
            ];
        })->values();

        return $this->success([
            'totalAmount' => (int) $books->sum('used_count'),
            'rows' => $rows,
        ], 'Receipt book report retrieved');
    }

    /**
     * Final hisab report.
     *
     * Middleware: auth:sanctum, TenantScope, RequireRole:ADMIN|TREASURER
     */
    public function finalHisab($festival)
    {
        $festivalModel = $this->resolveFestival($festival);
        $this->checkAdminOrTreasurer($festivalModel);

        $cacheKey = CacheKeyService::reportsFinalHisab($festival);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_REPORTS_FINAL_HISAB, function () use ($festivalModel) {
            $audit = FinalHisabAudit::where('festival_id', $festivalModel->id)->first();

            if ($audit) {
                $signerNames = User::whereIn('id', array_filter([
                    $audit->president_user_id,
                    $audit->treasurer_user_id,
                ]))->pluck('full_name', 'id');

                return $this->success(array_merge($this->hisabIdentity($festivalModel), [
                    'id' => $audit->id,
                    'festivalId' => $audit->festival_id,
                    'openingBalance' => (float) $audit->opening_balance,
                    'varganiTotal' => (float) $audit->vargani_total,
                    'otherIncomeTotal' => (float) $audit->other_income_total,
                    'totalIncome' => (float) $audit->total_income,
                    'totalExpenses' => (float) $audit->total_expenses,
                    'closingBalance' => (float) $audit->closing_balance,
                    'presidentSigned' => (bool) $audit->president_signed,
                    'treasurerSigned' => (bool) $audit->treasurer_signed,
                    'presidentName' => $audit->president_user_id ? $signerNames->get($audit->president_user_id) : null,
                    'treasurerName' => $audit->treasurer_user_id ? $signerNames->get($audit->treasurer_user_id) : null,
                    'presidentSignedAt' => $audit->president_signed_at?->toIso8601String(),
                    'treasurerSignedAt' => $audit->treasurer_signed_at?->toIso8601String(),
                    'presidentUserId' => $audit->president_user_id,
                    'treasurerUserId' => $audit->treasurer_user_id,
                    'treasurerAuthMethod' => $audit->treasurer_auth_method?->value,
                    'isLocked' => (bool) $audit->is_locked,
                    'pdfReportUrl' => $audit->pdf_report_url,
                ]), 'Final hisab retrieved');
            }

            $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
            $varganiTotal = (float) $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
            $otherIncomeTotal = (float) $festivalModel->otherIncomes()->sum('amount');
            $totalIncome = $openingBalance + $varganiTotal + $otherIncomeTotal;
            $totalExpenses = (float) $festivalModel->expenseEntries()->where('status', ExpenseStatus::PAID)->sum('amount');
            $closingBalance = $totalIncome - $totalExpenses;

            return $this->success(array_merge($this->hisabIdentity($festivalModel), [
                'festivalId' => $festivalModel->id,
                'openingBalance' => $openingBalance,
                'varganiTotal' => $varganiTotal,
                'otherIncomeTotal' => $otherIncomeTotal,
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'closingBalance' => $closingBalance,
                'presidentSigned' => false,
                'treasurerSigned' => false,
                'presidentName' => null,
                'treasurerName' => null,
                'presidentSignedAt' => null,
                'treasurerSignedAt' => null,
                'presidentUserId' => null,
                'treasurerUserId' => null,
                'treasurerAuthMethod' => null,
                'isLocked' => false,
                'pdfReportUrl' => null,
            ]), 'Final hisab computed live');
        });
    }

    /**
     * Sign final hisab report.
     *
     * Middleware: auth:sanctum, TenantScope, RequireRole:ADMIN
     */
    public function signFinalHisab(Request $request, $festival)
    {
        $festivalModel = $this->resolveFestival($festival);

        $membership = MandalMember::where('mandal_id', $festivalModel->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:PRESIDENT,TREASURER'],
            'signatureBase64' => ['nullable', 'string'],
            'authMethod' => ['required', 'in:PIN,BIOMETRIC'],
        ]);

        $role = $validated['role'];
        $authMethod = AuthMethod::from($validated['authMethod']);
        $userRole = $membership->role;

        // Only ADMIN or SUPER_ADMIN can sign as PRESIDENT
        if ($role === 'PRESIDENT') {
            if (! in_array($userRole, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)) {
                return $this->error('FORBIDDEN', 'Only ADMIN can sign as PRESIDENT', 403);
            }
        }

        // TREASURER (or ADMIN/SUPER_ADMIN) can sign as TREASURER
        if ($role === 'TREASURER') {
            if (! in_array($userRole, [MemberRole::TREASURER, MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)) {
                return $this->error('FORBIDDEN', 'Only TREASURER can sign as TREASURER', 403);
            }
        }

        $audit = FinalHisabAudit::where('festival_id', $festivalModel->id)->first();

        $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
        $varganiTotal = (float) $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
        $otherIncomeTotal = (float) $festivalModel->otherIncomes()->sum('amount');
        $totalIncome = $openingBalance + $varganiTotal + $otherIncomeTotal;
        $totalExpenses = (float) $festivalModel->expenseEntries()->where('status', ExpenseStatus::PAID)->sum('amount');
        $closingBalance = $totalIncome - $totalExpenses;

        if (! $audit) {
            $audit = FinalHisabAudit::create([
                'festival_id' => $festivalModel->id,
                'opening_balance' => $openingBalance,
                'vargani_total' => $varganiTotal,
                'other_income_total' => $otherIncomeTotal,
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'closing_balance' => $closingBalance,
                'president_signed' => false,
                'treasurer_signed' => false,
                'is_locked' => false,
            ]);
        } elseif (! $audit->is_locked) {
            // After unlock, entries may have been corrected; the re-signature
            // must cover the corrected numbers, not the pre-unlock snapshot.
            $audit->update([
                'opening_balance' => $openingBalance,
                'vargani_total' => $varganiTotal,
                'other_income_total' => $otherIncomeTotal,
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'closing_balance' => $closingBalance,
            ]);
        }

        $wasLocked = (bool) $audit->is_locked;

        if ($role === 'PRESIDENT') {
            $audit->update([
                'president_signed' => true,
                'president_signed_at' => now(),
                'president_user_id' => auth()->id(),
            ]);
        }

        if ($role === 'TREASURER') {
            $audit->update([
                'treasurer_signed' => true,
                'treasurer_signed_at' => now(),
                'treasurer_user_id' => auth()->id(),
                'treasurer_auth_method' => $authMethod,
            ]);
        }

        if ($audit->president_signed && $audit->treasurer_signed) {
            $audit->update(['is_locked' => true]);
            if (! $wasLocked) {
                $this->notifications->notifyFinalHisabLocked($festivalModel);
            }
        }

        CacheKeyService::forget(CacheKeyService::reportsFinalHisab($festivalModel->id));
        CacheKeyService::forget(CacheKeyService::reportsOverview($festivalModel->id));

        return $this->success([
            'id' => $audit->id,
            'presidentSigned' => (bool) $audit->president_signed,
            'treasurerSigned' => (bool) $audit->treasurer_signed,
            'isLocked' => (bool) $audit->is_locked,
        ], 'Final hisab signed successfully');
    }

    /**
     * Unlock a locked final hisab so financial entries can be corrected.
     * SUPER_ADMIN only: the lock is created by two signatures, so undoing it
     * requires a higher privilege than signing, confirmed by the security PIN.
     *
     * Middleware: role:SUPER_ADMIN
     */
    public function unlockFinalHisab(Request $request, $festival)
    {
        $festivalModel = $this->resolveFestival($festival);

        $membership = MandalMember::where('mandal_id', $festivalModel->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        $validated = $request->validate([
            'pin' => ['nullable', 'string', 'digits:4'],
        ]);

        $user = auth()->user();
        if (empty($user->security_pin)) {
            return $this->error('PIN_NOT_SET', 'Set a security PIN in Profile settings before unlocking the final hisab', 422);
        }

        if (empty($validated['pin'])) {
            return $this->error('PIN_REQUIRED', 'Security PIN is required to unlock the final hisab', 422);
        }

        if (! Hash::check($validated['pin'], $user->security_pin)) {
            return $this->error('INVALID_PIN', 'The entered PIN is incorrect', 422);
        }

        $audit = FinalHisabAudit::where('festival_id', $festivalModel->id)->first();

        if (! $audit || ! $audit->is_locked) {
            return $this->error('VALIDATION_FAILED', 'Final hisab is not locked', 422);
        }

        $audit->update(['is_locked' => false]);

        $this->notifications->notifyFinalHisabUnlocked($festivalModel);

        CacheKeyService::forget(CacheKeyService::reportsFinalHisab($festivalModel->id));
        CacheKeyService::forget(CacheKeyService::reportsOverview($festivalModel->id));

        return $this->success([
            'id' => $audit->id,
            'presidentSigned' => (bool) $audit->president_signed,
            'treasurerSigned' => (bool) $audit->treasurer_signed,
            'isLocked' => false,
        ], 'Final hisab unlocked');
    }

    /**
     * Download final hisab PDF.
     *
     * Middleware: jwt.auth, tenant.scope, rate.limit + controller checkAdminOrTreasurer
     */
    public function finalHisabPdf($festival)
    {
        $festivalModel = $this->resolveFestival($festival);
        $festivalModel->loadMissing('mandal');
        $this->checkAdminOrTreasurer($festivalModel);

        $audit = FinalHisabAudit::where('festival_id', $festivalModel->id)->first();

        if ($audit) {
            $data = [
                'festival' => $festivalModel,
                'openingBalance' => (float) $audit->opening_balance,
                'varganiTotal' => (float) $audit->vargani_total,
                'otherIncomeTotal' => (float) $audit->other_income_total,
                'totalIncome' => (float) $audit->total_income,
                'totalExpenses' => (float) $audit->total_expenses,
                'closingBalance' => (float) $audit->closing_balance,
                'presidentSigned' => (bool) $audit->president_signed,
                'treasurerSigned' => (bool) $audit->treasurer_signed,
                'presidentSignedAt' => $audit->president_signed_at,
                'treasurerSignedAt' => $audit->treasurer_signed_at,
                'isLocked' => (bool) $audit->is_locked,
            ];
        } else {
            $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
            $varganiTotal = (float) $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
            $otherIncomeTotal = (float) $festivalModel->otherIncomes()->sum('amount');
            $totalIncome = $openingBalance + $varganiTotal + $otherIncomeTotal;
            $totalExpenses = (float) $festivalModel->expenseEntries()->where('status', ExpenseStatus::PAID)->sum('amount');
            $closingBalance = $totalIncome - $totalExpenses;

            $data = [
                'festival' => $festivalModel,
                'openingBalance' => $openingBalance,
                'varganiTotal' => $varganiTotal,
                'otherIncomeTotal' => $otherIncomeTotal,
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'closingBalance' => $closingBalance,
                'presidentSigned' => false,
                'treasurerSigned' => false,
                'presidentName' => null,
                'treasurerName' => null,
                'presidentSignedAt' => null,
                'treasurerSignedAt' => null,
                'isLocked' => false,
            ];
        }

        $pdf = Pdf::loadHTML(View::make('pdf.final_hisab', $data)->render());

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="final-hisab.pdf"',
        ]);
    }
}
