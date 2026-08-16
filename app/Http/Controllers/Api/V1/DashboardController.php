<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMode;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\MandalMember;
use App\Services\CacheKeyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DashboardController
{
    use ApiResponse;

    /**
     * GET /api/v1/festivals/:festivalId/dashboard/summary
     */
    public function summary(Request $request, $festival)
    {
        $user = $request->user();
        $festival = Festival::findOrFail($festival);

        $membership = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        $cacheKey = CacheKeyService::dashboard($festival->id);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_DASHBOARD, function () use ($festival) {
            // Core aggregates
            $varganiQuery = $festival->varganiEntries()->where('is_cancelled', false);
            $totalCollected = (float) $varganiQuery->sum('amount');
            $totalExpenses = (float) $festival->expenseEntries()->where('status', 'PAID')->sum('amount');
            $netBalance = $totalCollected - $totalExpenses;
            $budgetGoal = (float) $festival->budget_goal;
            $progressPercentage = $budgetGoal > 0 ? round(($totalCollected / $budgetGoal) * 100, 1) : 0;

            // Fund breakdown from the explicit FestivalBalance ledger (single-row, O(1))
            $balance = FestivalBalance::forFestival($festival->id);
            $cashTreasurer = (float) $balance->cash_treasurer;
            $cashCollectors = (float) $balance->cash_collectors;
            $bankTotal = (float) $balance->bank;
            $upiTotal = (float) $balance->upi;
            $cashInHand = $cashTreasurer + $cashCollectors;

            // Pending with collectors = cash vargani not yet handed over (computed from DB for accuracy)
            $handedOverEntryIds = collect();
            $handovers = $festival->cashHandovers()
                ->where('status', 'VERIFIED_ACCEPTED')
                ->get(['linked_entry_ids']);
            foreach ($handovers as $h) {
                $handedOverEntryIds = $handedOverEntryIds->merge($h->linked_entry_ids ?? []);
            }

            $pendingCashWithCollectors = (float) $varganiQuery->clone()
                ->where('payment_mode', PaymentMode::CASH)
                ->when($handedOverEntryIds->isNotEmpty(), function ($q) use ($handedOverEntryIds) {
                    $q->whereNotIn('id', $handedOverEntryIds->unique()->values()->all());
                })
                ->sum('amount');

            $totalDonorsCount = $varganiQuery->clone()->distinct('donor_name')->count('donor_name');
            $totalReceiptsIssued = $varganiQuery->clone()->count();

            // Recent transactions (last 10 vargani + expenses mixed, ordered by created_at desc)
            $recentVargani = $festival->varganiEntries()
                ->where('is_cancelled', false)
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($v) {
                    $mode = $v->payment_mode instanceof \BackedEnum ? $v->payment_mode->value : (string) $v->payment_mode;
                    return [
                        'id' => $v->id,
                        'type' => 'VARGANI',
                        'title' => $v->donor_name,
                        'subtitle' => 'Receipt #' . $v->receipt_number . ' • ' . $mode,
                        'amount' => (float) $v->amount,
                        'paymentMode' => $mode,
                        'timestamp' => $v->created_at?->toIso8601String(),
                    ];
                });

            $recentExpenses = $festival->expenseEntries()
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($e) {
                    $cat = $e->category instanceof \BackedEnum ? $e->category->value : (string) $e->category;
                    $mode = $e->payment_mode instanceof \BackedEnum ? $e->payment_mode->value : (string) $e->payment_mode;
                    return [
                        'id' => $e->id,
                        'type' => 'EXPENSE',
                        'title' => $e->title,
                        'subtitle' => $cat . ' • ' . $mode,
                        'amount' => -(float) $e->amount,
                        'paymentMode' => $mode,
                        'timestamp' => $e->created_at?->toIso8601String(),
                    ];
                });

            $recentTransactions = $recentVargani->merge($recentExpenses)
                ->sortByDesc('timestamp')
                ->take(10)
                ->values();

            $festStatus = $festival->status instanceof \BackedEnum ? $festival->status->value : (string) $festival->status;

            return $this->success([
                'festival' => [
                    'id' => $festival->id,
                    'name' => $festival->name . ' ' . $festival->year,
                    'status' => $festStatus,
                ],
                'metrics' => [
                    'totalCollected' => $totalCollected,
                    'budgetGoal' => $budgetGoal,
                    'progressPercentage' => $progressPercentage,
                    'totalExpenses' => $totalExpenses,
                    'netBalance' => $netBalance,
                    'cashInHand' => max(0, $cashInHand),
                    'cashTreasurer' => $cashTreasurer,
                    'cashCollectors' => $cashCollectors,
                    'bankBalance' => $bankTotal,
                    'upiBalance' => $upiTotal,
                    'totalDonorsCount' => $totalDonorsCount,
                    'totalReceiptsIssued' => $totalReceiptsIssued,
                    'pendingCashWithCollectors' => $pendingCashWithCollectors,
                ],
                'recentTransactions' => $recentTransactions,
            ], 'Dashboard summary retrieved');
        });
    }
}
