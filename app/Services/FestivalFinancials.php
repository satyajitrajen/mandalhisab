<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\OtherIncome;
use App\Models\VarganiEntry;

/**
 * Single hisab calculator for a festival.
 *
 * Vargani stays collection-only (progress / Total Vargani).
 * Total income is vargani + other income. Opening is not income.
 * Nets use PAID expenses only.
 * Closing = opening + vargani + other − paid expenses.
 */
class FestivalFinancials
{
    /**
     * @return array{
     *     opening_balance: float,
     *     vargani_total: float,
     *     other_income_total: float,
     *     total_income: float,
     *     paid_expenses: float,
     *     closing_balance: float,
     *     progress_ratio: float,
     *     progress_percentage: float
     * }
     */
    public function forFestival(Festival|string $festival): array
    {
        $model = $festival instanceof Festival
            ? $festival
            : Festival::findOrFail($festival);

        $varganiTotal = (float) VarganiEntry::where('festival_id', $model->id)
            ->where('is_cancelled', false)
            ->sum('amount');

        $otherIncomeTotal = (float) OtherIncome::where('festival_id', $model->id)
            ->sum('amount');

        $paidExpenses = (float) ExpenseEntry::where('festival_id', $model->id)
            ->where('status', ExpenseStatus::PAID)
            ->sum('amount');

        $opening = (float) ($model->opening_balance ?? 0);
        $totalIncome = $varganiTotal + $otherIncomeTotal;
        $budget = (float) ($model->budget_goal ?? 0);

        return [
            'opening_balance' => $opening,
            'vargani_total' => $varganiTotal,
            'other_income_total' => $otherIncomeTotal,
            'total_income' => $totalIncome,
            'paid_expenses' => $paidExpenses,
            'closing_balance' => $opening + $totalIncome - $paidExpenses,
            'progress_ratio' => $budget > 0 ? round($varganiTotal / $budget, 3) : 0.0,
            'progress_percentage' => $budget > 0 ? round(($varganiTotal / $budget) * 100, 1) : 0.0,
        ];
    }
}
