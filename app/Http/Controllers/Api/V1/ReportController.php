<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthMethod;
use App\Enums\ExpenseStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\FinalHisabAudit;
use App\Models\MandalMember;
use App\Models\OtherIncome;
use App\Models\VarganiEntry;
use App\Services\CacheKeyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class ReportController
{
    use ApiResponse;

    protected function resolveFestival($festivalId): Festival
    {
        return Festival::findOrFail($festivalId);
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
            $varganiQuery = $festivalModel->varganiEntries()->where('is_cancelled', false);

            $totalVargani = (float) $varganiQuery->sum('amount');
            $totalExpenses = (float) $festivalModel->expenseEntries()->sum('amount');
            $totalOtherIncome = (float) $festivalModel->otherIncomes()->sum('amount');
            $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
            $netBalance = $totalVargani + $totalOtherIncome + $openingBalance - $totalExpenses;

            $totalDonors = $varganiQuery->clone()->count();
            $totalReceipts = $totalDonors;

            $totalCash = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::CASH)->sum('amount');
            $totalUPI = (float) $varganiQuery->clone()->where('payment_mode', PaymentMode::UPI)->sum('amount');
            $totalBank = (float) $varganiQuery->clone()->whereIn('payment_mode', [PaymentMode::CHEQUE, PaymentMode::NET_BANKING])->sum('amount');

            $expenseByCategory = $festivalModel->expenseEntries()
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
                'totalExpenses' => $totalExpenses,
                'totalOtherIncome' => $totalOtherIncome,
                'netBalance' => $netBalance,
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

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_REPORTS_TYPED, function () use ($festivalModel, $reportType, $startDate, $endDate) {
            switch ($reportType) {
                case 'vargani_summary':
                    return $this->varganiSummary($festivalModel, $startDate, $endDate);
                case 'expense_summary':
                    return $this->expenseSummary($festivalModel, $startDate, $endDate);
                case 'fund_summary':
                    return $this->fundSummary($festivalModel);
                case 'member_activity':
                    return $this->memberActivity($festivalModel, $startDate, $endDate);
                default:
                    return $this->error('INVALID_REPORT_TYPE', 'Unsupported report type', 400);
            }
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

        return $this->success([
            'byPaymentMode' => $byPaymentMode,
            'byArea' => $byArea,
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

        return $this->success([
            'byCategory' => $byCategory,
            'byStatus' => $byStatus,
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

        return $this->success([
            'buckets' => $buckets,
            'totalCollected' => $cashTotal + $upiTotal + $bankTotal,
            'totalExpenses' => $cashExpenses + (float) $festival->expenseEntries()->where('status', ExpenseStatus::PAID)->whereIn('payment_mode', [PaymentMode::UPI, PaymentMode::CHEQUE, PaymentMode::NET_BANKING])->sum('amount'),
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

        return $this->success($activity, 'Member activity retrieved');
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
                return $this->success([
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
                    'presidentSignedAt' => $audit->president_signed_at?->toIso8601String(),
                    'treasurerSignedAt' => $audit->treasurer_signed_at?->toIso8601String(),
                    'presidentUserId' => $audit->president_user_id,
                    'treasurerUserId' => $audit->treasurer_user_id,
                    'treasurerAuthMethod' => $audit->treasurer_auth_method?->value,
                    'isLocked' => (bool) $audit->is_locked,
                    'pdfReportUrl' => $audit->pdf_report_url,
                ], 'Final hisab retrieved');
            }

            $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
            $varganiTotal = (float) $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
            $otherIncomeTotal = (float) $festivalModel->otherIncomes()->sum('amount');
            $totalIncome = $openingBalance + $varganiTotal + $otherIncomeTotal;
            $totalExpenses = (float) $festivalModel->expenseEntries()->where('status', ExpenseStatus::PAID)->sum('amount');
            $closingBalance = $totalIncome - $totalExpenses;

            return $this->success([
                'festivalId' => $festivalModel->id,
                'openingBalance' => $openingBalance,
                'varganiTotal' => $varganiTotal,
                'otherIncomeTotal' => $otherIncomeTotal,
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'closingBalance' => $closingBalance,
                'presidentSigned' => false,
                'treasurerSigned' => false,
                'presidentSignedAt' => null,
                'treasurerSignedAt' => null,
                'presidentUserId' => null,
                'treasurerUserId' => null,
                'treasurerAuthMethod' => null,
                'isLocked' => false,
                'pdfReportUrl' => null,
            ], 'Final hisab computed live');
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

        if (! $audit) {
            $openingBalance = (float) ($festivalModel->opening_balance ?? 0);
            $varganiTotal = (float) $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
            $otherIncomeTotal = (float) $festivalModel->otherIncomes()->sum('amount');
            $totalIncome = $openingBalance + $varganiTotal + $otherIncomeTotal;
            $totalExpenses = (float) $festivalModel->expenseEntries()->where('status', ExpenseStatus::PAID)->sum('amount');
            $closingBalance = $totalIncome - $totalExpenses;

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
        }

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
     * Download final hisab PDF.
     *
     * Middleware: jwt.auth, tenant.scope, rate.limit + controller checkAdminOrTreasurer
     */
    public function finalHisabPdf($festival)
    {
        $festivalModel = $this->resolveFestival($festival);
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
                'presidentSignedAt' => null,
                'treasurerSignedAt' => null,
                'isLocked' => false,
            ];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML(View::make('pdf.final_hisab', $data)->render());

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="final-hisab.pdf"',
        ]);
    }
}
