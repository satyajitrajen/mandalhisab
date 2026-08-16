<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FestivalStatus;
use App\Enums\MemberRole;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\MandalMember;
use App\Models\ReceiptSequence;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FestivalController
{
    use ApiResponse;

    /**
     * GET /api/v1/mandals/:mandalId/festivals
     */
    public function index(Request $request, $mandal)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:ACTIVE,UPCOMING,COMPLETED'],
            'search' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        $query = Festival::where('mandal_id', $mandal);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $festivals = $query->orderBy('start_date', 'desc')->get();

        $data = $festivals->map(function ($festival) {
            $totalCollection = $festival->varganiEntries()->where('is_cancelled', false)->sum('amount');
            $totalExpense = $festival->expenseEntries()->where('status', 'PAID')->sum('amount');

            return [
                'id' => $festival->id,
                'name' => $festival->name,
                'year' => (string) $festival->year,
                'mandalName' => $festival->mandal->name ?? null,
                'startDate' => $festival->start_date?->format('Y-m-d'),
                'endDate' => $festival->end_date?->format('Y-m-d'),
                'status' => $festival->status->value,
                'budgetGoal' => (float) $festival->budget_goal,
                'totalCollection' => (float) $totalCollection,
                'totalExpense' => (float) $totalExpense,
                'closingBalance' => (float) ($totalCollection - $totalExpense),
                'progressRatio' => $festival->budget_goal > 0 ? round($totalCollection / $festival->budget_goal, 3) : 0,
                'description' => $festival->description,
            ];
        });

        return $this->success($data, 'Festival list retrieved');
    }

    /**
     * POST /api/v1/mandals/:mandalId/festivals
     */
    public function store(Request $request, $mandal)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'string', 'size:4'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'budgetGoal' => ['nullable', 'numeric', 'min:0'],
            'openingBalance' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::TREASURER])
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'Only ADMIN or TREASURER can create festivals', 403);
        }

        $festival = Festival::create([
            'mandal_id' => $mandal,
            'name' => $validated['name'],
            'year' => $validated['year'],
            'start_date' => $validated['startDate'],
            'end_date' => $validated['endDate'],
            'status' => FestivalStatus::ACTIVE,
            'budget_goal' => $validated['budgetGoal'] ?? 0,
            'opening_balance' => $validated['openingBalance'] ?? 0,
            'description' => $validated['description'] ?? null,
        ]);

        FestivalBalance::create([
            'festival_id' => $festival->id,
            'cash_treasurer' => 0,
            'cash_collectors' => 0,
            'bank' => 0,
            'upi' => 0,
            'version' => 0,
        ]);

        ReceiptSequence::firstOrCreate(
            ['festival_id' => $festival->id],
            ['next_number' => 1]
        );

        return $this->success([
            'id' => $festival->id,
            'name' => $festival->name,
            'year' => $festival->year,
            'startDate' => $festival->start_date?->format('Y-m-d'),
            'endDate' => $festival->end_date?->format('Y-m-d'),
            'status' => $festival->status->value,
            'budgetGoal' => (float) $festival->budget_goal,
        ], 'Festival created successfully', 201);
    }

    /**
     * GET /api/v1/festivals/:festivalId
     */
    public function show(Request $request, $festival)
    {
        $festivalModel = Festival::find($festival);
        if (! $festivalModel) {
            return $this->error('NOT_FOUND', 'Festival not found', 404);
        }

        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $festivalModel->mandal_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        $totalCollection = $festivalModel->varganiEntries()->where('is_cancelled', false)->sum('amount');
        $totalExpense = $festivalModel->expenseEntries()->where('status', 'PAID')->sum('amount');

        return $this->success([
            'id' => $festivalModel->id,
            'mandalId' => $festivalModel->mandal_id,
            'name' => $festivalModel->name,
            'year' => (string) $festivalModel->year,
            'startDate' => $festivalModel->start_date?->format('Y-m-d'),
            'endDate' => $festivalModel->end_date?->format('Y-m-d'),
            'status' => $festivalModel->status->value,
            'budgetGoal' => (float) $festivalModel->budget_goal,
            'openingBalance' => (float) $festivalModel->opening_balance,
            'description' => $festivalModel->description,
            'totalCollection' => (float) $totalCollection,
            'totalExpense' => (float) $totalExpense,
            'closingBalance' => (float) ($totalCollection - $totalExpense),
            'createdAt' => $festivalModel->created_at,
            'updatedAt' => $festivalModel->updated_at,
        ], 'Festival details retrieved');
    }

    /**
     * PUT /api/v1/festivals/:festivalId
     */
    public function update(Request $request, $festival)
    {
        $festivalModel = Festival::find($festival);
        if (! $festivalModel) {
            return $this->error('NOT_FOUND', 'Festival not found', 404);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'budgetGoal' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:ACTIVE,UPCOMING,COMPLETED'],
        ]);

        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $festivalModel->mandal_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        // TREASURER can only update budget_goal and opening_balance
        $isAdmin = in_array($membership->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN]);
        $isTreasurer = $membership->role === MemberRole::TREASURER;

        if (! $isAdmin && ! $isTreasurer) {
            return $this->error('FORBIDDEN', 'Only ADMIN or TREASURER can update festivals', 403);
        }

        $updateData = [];

        if ($isAdmin) {
            if (isset($validated['name'])) $updateData['name'] = $validated['name'];
            if (isset($validated['startDate'])) $updateData['start_date'] = $validated['startDate'];
            if (isset($validated['endDate'])) $updateData['end_date'] = $validated['endDate'];
            if (isset($validated['description'])) $updateData['description'] = $validated['description'];
            if (isset($validated['status'])) $updateData['status'] = FestivalStatus::from($validated['status']);
        }

        if ($isAdmin || $isTreasurer) {
            if (isset($validated['budgetGoal'])) $updateData['budget_goal'] = $validated['budgetGoal'];
        }

        $festivalModel->update($updateData);

        return $this->success([
            'id' => $festivalModel->id,
            'name' => $festivalModel->name,
            'status' => $festivalModel->status->value,
            'budgetGoal' => (float) $festivalModel->budget_goal,
        ], 'Festival updated successfully');
    }
}
