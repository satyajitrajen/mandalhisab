<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Models\ExpenseEntry;
use App\Models\MandalMember;
use App\Models\User;
use App\Models\VarganiEntry;
use App\Services\CacheKeyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberController
{
    use ApiResponse;

    /**
     * GET /api/v1/mandals/{mandal}/members
     */
    public function index(Request $request, $mandal)
    {
        $user = $request->user();

        $membership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not an active member of this mandal', 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:ADMIN,TREASURER,COLLECTOR,MEMBER'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'search' => $validated['search'] ?? null,
            'role' => $validated['role'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::membersList($mandal, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_MEMBERS_LIST, function () use ($mandal, $validated) {
            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;

            $query = MandalMember::with('user')
                ->where('mandal_id', $mandal);

            if (! empty($validated['search'])) {
                $search = $validated['search'];
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('full_name', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            if (! empty($validated['role'])) {
                $query->where('role', $validated['role']);
            }

            $paginator = $query->orderBy('joined_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            $paginator->getCollection()->transform(function ($mm) {
                return [
                    'id' => $mm->id,
                    'fullName' => $mm->user->full_name ?? null,
                    'phone' => $mm->user->phone ?? null,
                    'role' => $mm->role->value,
                    'area' => $mm->area,
                    'isActive' => $mm->is_active,
                    'isDefault' => $mm->is_default,
                    'joinedAt' => $mm->joined_at,
                ];
            });

            return $this->paginated($paginator, 'Member list retrieved');
        });
    }

    /**
     * GET /api/v1/mandals/{mandal}/members/{member}
     * GET /api/v1/members/{member}
     */
    public function show(Request $request, ...$args)
    {
        $memberId = end($args);
        $memberRecord = MandalMember::with('user')->find($memberId);

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found', 404);
        }

        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $memberRecord->mandal_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'You are not an active member of this mandal', 403);
        }

        return $this->success([
            'id' => $memberRecord->id,
            'fullName' => $memberRecord->user->full_name ?? null,
            'phone' => $memberRecord->user->phone ?? null,
            'role' => $memberRecord->role->value,
            'area' => $memberRecord->area,
            'isActive' => $memberRecord->is_active,
            'isDefault' => $memberRecord->is_default,
            'joinedAt' => $memberRecord->joined_at,
        ], 'Member details retrieved');
    }

    /**
     * POST /api/v1/mandals/{mandal}/members
     */
    public function store(Request $request, $mandal)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can add members', 403);
        }

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
            'role' => ['required', 'in:ADMIN,TREASURER,COLLECTOR,MEMBER'],
            'area' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $member = DB::transaction(function () use ($validated, $mandal) {
                $existingUser = User::where('phone', $validated['phone'])->first();

                if ($existingUser) {
                    $existingMember = MandalMember::where('mandal_id', $mandal)
                        ->where('user_id', $existingUser->id)
                        ->first();

                    if ($existingMember) {
                        throw new \RuntimeException('User is already a member of this mandal');
                    }

                    $user = $existingUser;
                } else {
                    $user = User::create([
                        'full_name' => $validated['fullName'],
                        'phone' => $validated['phone'],
                        'username' => null,
                        'password' => Str::random(16),
                        'default_language' => 'en',
                    ]);
                }

                $mandalMember = MandalMember::create([
                    'mandal_id' => $mandal,
                    'user_id' => $user->id,
                    'role' => MemberRole::from($validated['role']),
                    'area' => $validated['area'] ?? null,
                    'is_active' => true,
                    'is_default' => false,
                    'joined_at' => now(),
                ]);

                return $mandalMember;
            });
        } catch (\RuntimeException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        CacheKeyService::clearMembers($mandal);

        return $this->success([
            'id' => $member->id,
            'fullName' => $member->user->full_name ?? null,
            'phone' => $member->user->phone ?? null,
            'role' => $member->role->value,
            'area' => $member->area,
            'isActive' => $member->is_active,
            'isDefault' => $member->is_default,
            'joinedAt' => $member->joined_at,
        ], 'Member created successfully', 201);
    }

    /**
     * PUT/PATCH /api/v1/mandals/{mandal}/members/{member}
     * PUT/PATCH /api/v1/members/{member}
     */
    public function update(Request $request, ...$args)
    {
        $member = end($args);
        $memberRecord = MandalMember::with('user')->find($member);

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found', 404);
        }

        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $memberRecord->mandal_id)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can update members', 403);
        }

        $validated = $request->validate([
            'role' => ['nullable', 'in:ADMIN,TREASURER,COLLECTOR,MEMBER'],
            'area' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($validated['role'])
            && in_array($memberRecord->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
            && $validated['role'] !== MemberRole::ADMIN->value
        ) {
            $adminCount = MandalMember::where('mandal_id', $memberRecord->mandal_id)
                ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
                ->where('is_active', true)
                ->count();

            if ($adminCount <= 1) {
                return $this->error('VALIDATION_FAILED', 'Cannot demote the last ADMIN', 422);
            }
        }

        $updateData = [];

        if (! empty($validated['role'])) {
            $updateData['role'] = MemberRole::from($validated['role']);
        }

        if (array_key_exists('area', $validated)) {
            $updateData['area'] = $validated['area'] ?: null;
        }

        $memberRecord->update($updateData);

        CacheKeyService::clearMembers($memberRecord->mandal_id);

        return $this->success([
            'id' => $memberRecord->id,
            'fullName' => $memberRecord->user->full_name ?? null,
            'phone' => $memberRecord->user->phone ?? null,
            'role' => $memberRecord->role->value,
            'area' => $memberRecord->area,
            'isActive' => $memberRecord->is_active,
            'isDefault' => $memberRecord->is_default,
            'joinedAt' => $memberRecord->joined_at,
        ], 'Member updated successfully');
    }

    /**
     * GET /api/v1/mandals/{mandal}/members/{member}/financial-summary
     */
    public function financialSummary(Request $request, $mandal, $member)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::TREASURER])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN or TREASURER can view financial summary', 403);
        }

        $memberRecord = MandalMember::where('mandal_id', $mandal)
            ->where('id', $member)
            ->first();

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found in this mandal', 404);
        }

        $userId = $memberRecord->user_id;

        $totalVargani = VarganiEntry::where('mandal_id', $mandal)
            ->where('collector_id', $userId)
            ->where('is_cancelled', false)
            ->sum('amount') ?? 0;

        $receiptCount = VarganiEntry::where('mandal_id', $mandal)
            ->where('collector_id', $userId)
            ->where('is_cancelled', false)
            ->count();

        $totalExpenses = ExpenseEntry::whereHas('festival', function ($q) use ($mandal) {
                $q->where('mandal_id', $mandal);
            })
            ->where('created_by_user_id', $userId)
            ->sum('amount') ?? 0;

        $expenseCount = ExpenseEntry::whereHas('festival', function ($q) use ($mandal) {
                $q->where('mandal_id', $mandal);
            })
            ->where('created_by_user_id', $userId)
            ->count();

        return $this->success([
            'totalVarganiCollected' => (float) $totalVargani,
            'totalExpensesCreated' => (float) $totalExpenses,
            'receiptCount' => (int) $receiptCount,
            'expenseCount' => (int) $expenseCount,
        ], 'Financial summary retrieved');
    }

    /**
     * POST /api/v1/mandals/{mandal}/members/{member}/deactivate
     */
    public function deactivate(Request $request, $mandal, $member)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can deactivate members', 403);
        }

        $memberRecord = MandalMember::where('mandal_id', $mandal)
            ->where('id', $member)
            ->first();

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found in this mandal', 404);
        }

        if (in_array($memberRecord->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)) {
            $adminCount = MandalMember::where('mandal_id', $mandal)
                ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
                ->where('is_active', true)
                ->count();

            if ($adminCount <= 1) {
                return $this->error('VALIDATION_FAILED', 'Cannot deactivate the last ADMIN', 422);
            }
        }

        $memberRecord->update(['is_active' => false]);

        CacheKeyService::clearMembers($mandal);

        return $this->success(null, 'Member deactivated successfully');
    }
}
