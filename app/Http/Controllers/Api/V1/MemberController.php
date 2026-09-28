<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Enums\ReceiptBookStatus;
use App\Models\CashHandover;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\ReceiptBook;
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
            'festivalId' => ['nullable', 'string'],
        ]);

        $festivalId = $request->query('festivalId') ?? $request->header('X-Festival-Id');
        $scopeFestivalId = null;
        if ($festivalId && Festival::where('id', $festivalId)->where('mandal_id', $mandal)->exists()) {
            $scopeFestivalId = $festivalId;
        } else {
            $scopeFestivalId = Festival::where('mandal_id', $mandal)
                ->where('status', \App\Enums\FestivalStatus::ACTIVE)
                ->value('id')
                ?? Festival::where('mandal_id', $mandal)->latest('created_at')->value('id');
        }

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'search' => $validated['search'] ?? null,
            'role' => $validated['role'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
            'festivalId' => $scopeFestivalId,
        ]));
        $cacheKey = CacheKeyService::membersList($mandal, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_MEMBERS_LIST, function () use ($mandal, $validated, $scopeFestivalId) {
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

            $userIds = $paginator->getCollection()->pluck('user_id')->filter()->unique()->values();

            $varganiQuery = VarganiEntry::where('mandal_id', $mandal)
                ->whereIn('collector_id', $userIds)
                ->where('is_cancelled', false);

            $handoverQuery = CashHandover::whereHas('festival', function ($q) use ($mandal) {
                $q->where('mandal_id', $mandal);
            })
                ->whereIn('from_user_id', $userIds)
                ->where('status', HandoverStatus::VERIFIED_ACCEPTED);

            if ($scopeFestivalId) {
                $varganiQuery->where('festival_id', $scopeFestivalId);
                $handoverQuery->where('festival_id', $scopeFestivalId);
            }

            $collectedAmounts = (clone $varganiQuery)
                ->selectRaw('collector_id, SUM(amount) as total')
                ->groupBy('collector_id')
                ->pluck('total', 'collector_id')
                ->all();

            $cashAmounts = (clone $varganiQuery)
                ->where('payment_mode', PaymentMode::CASH->value)
                ->selectRaw('collector_id, SUM(amount) as total')
                ->groupBy('collector_id')
                ->pluck('total', 'collector_id')
                ->all();

            $cashSubmittedAmounts = (clone $handoverQuery)
                ->selectRaw('from_user_id, SUM(amount) as total')
                ->groupBy('from_user_id')
                ->pluck('total', 'from_user_id')
                ->all();

            $paginator->getCollection()->transform(function ($mm) use ($collectedAmounts, $cashAmounts, $cashSubmittedAmounts) {
                $userId = $mm->user_id;
                $collected = (float) ($collectedAmounts[$userId] ?? 0);
                $cash = (float) ($cashAmounts[$userId] ?? 0);
                $submitted = (float) ($cashSubmittedAmounts[$userId] ?? 0);
                $pending = max($cash - $submitted, 0.0);

                return [
                    'id' => $mm->id,
                    'membershipId' => $mm->id,
                    'name' => $mm->user->full_name ?? null,
                    'fullName' => $mm->user->full_name ?? null,
                    'initials' => $mm->user?->initials ?? '',
                    'phone' => $mm->user->phone ?? null,
                    'role' => $mm->role->value,
                    'collectedAmount' => $collected,
                    'cashPending' => $pending,
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

        $festivalId = $request->query('festivalId') ?? $request->header('X-Festival-Id');
        $scopeFestivalId = null;
        if ($festivalId && Festival::where('id', $festivalId)->where('mandal_id', $memberRecord->mandal_id)->exists()) {
            $scopeFestivalId = $festivalId;
        } else {
            $scopeFestivalId = Festival::where('mandal_id', $memberRecord->mandal_id)
                ->where('status', \App\Enums\FestivalStatus::ACTIVE)
                ->value('id')
                ?? Festival::where('mandal_id', $memberRecord->mandal_id)->latest('created_at')->value('id');
        }

        $userId = $memberRecord->user_id;
        $varganiQuery = VarganiEntry::where('mandal_id', $memberRecord->mandal_id)
            ->where('collector_id', $userId)
            ->where('is_cancelled', false);

        $handoverQuery = CashHandover::whereHas('festival', function ($q) use ($memberRecord) {
            $q->where('mandal_id', $memberRecord->mandal_id);
        })
            ->where('from_user_id', $userId)
            ->where('status', HandoverStatus::VERIFIED_ACCEPTED);

        if ($scopeFestivalId) {
            $varganiQuery->where('festival_id', $scopeFestivalId);
            $handoverQuery->where('festival_id', $scopeFestivalId);
        }

        $collected = (float) (clone $varganiQuery)->sum('amount');
        $cash = (float) (clone $varganiQuery)->where('payment_mode', PaymentMode::CASH->value)->sum('amount');
        $submitted = (float) (clone $handoverQuery)->sum('amount');
        $pending = max($cash - $submitted, 0.0);

        return $this->success([
            'id' => $memberRecord->id,
            'membershipId' => $memberRecord->id,
            'name' => $memberRecord->user->full_name ?? null,
            'fullName' => $memberRecord->user->full_name ?? null,
            'initials' => $memberRecord->user?->initials ?? '',
            'phone' => $memberRecord->user->phone ?? null,
            'role' => $memberRecord->role->value,
            'collectedAmount' => $collected,
            'cashPending' => $pending,
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

        if ($request->has('phone')) {
            $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
            if (strlen($digits) >= 10) {
                $request->merge(['phone' => substr($digits, -10)]);
            }
        }

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'role' => ['required', 'in:ADMIN,TREASURER,COLLECTOR,MEMBER'],
            'area' => ['nullable', 'string', 'max:255'],
        ]);

        $mandalModel = Mandal::find($mandal);
        if ($mandalModel && $mandalModel->contact_number) {
            $mandalContact = substr(preg_replace('/\D/', '', (string) $mandalModel->contact_number), -10);
            $memberPhone = substr(preg_replace('/\D/', '', (string) $validated['phone']), -10);
            if ($mandalContact !== '' && $mandalContact === $memberPhone) {
                return $this->error(
                    'VALIDATION_FAILED',
                    'Member mobile number cannot be the same as the Mandal registered contact number',
                    422,
                    [['field' => 'phone', 'issue' => 'Member mobile number cannot be the same as the Mandal registered contact number']]
                );
            }
        }

        try {
            $created = DB::transaction(function () use ($validated, $mandal) {
                $existingUser = User::where('phone', $validated['phone'])->first();
                $temporaryPassword = null;

                if ($existingUser) {
                    $existingMember = MandalMember::where('mandal_id', $mandal)
                        ->where('user_id', $existingUser->id)
                        ->first();

                    if ($existingMember) {
                        throw new \RuntimeException('User is already a member of this mandal');
                    }

                    $user = $existingUser;
                } else {
                    $temporaryPassword = self::generateTemporaryPassword();
                    $user = User::create([
                        'full_name' => $validated['fullName'],
                        'phone' => $validated['phone'],
                        'username' => $validated['phone'],
                        'password' => $temporaryPassword,
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

                return [
                    'member' => $mandalMember->load('user'),
                    'temporaryPassword' => $temporaryPassword,
                ];
            });
        } catch (\RuntimeException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\Illuminate\Database\QueryException $e) {
            // Raw DB messages can embed SQL; never echo them back.
            report($e);
            return $this->error('INTERNAL_ERROR', 'Could not create member', 500);
        }

        CacheKeyService::clearMembers($mandal);
        $member = $created['member'];

        return $this->success([
            'id' => $member->id,
            'fullName' => $member->user->full_name ?? null,
            'name' => $member->user->full_name ?? null,
            'phone' => $member->user->phone ?? null,
            'role' => $member->role->value,
            'area' => $member->area,
            'isActive' => $member->is_active,
            'isDefault' => $member->is_default,
            'joinedAt' => $member->joined_at,
            'temporaryPassword' => $created['temporaryPassword'],
            'username' => $member->user->username ?? $member->user->phone,
        ], 'Member created successfully', 201);
    }

    /**
     * PUT/PATCH /api/v1/mandals/{mandal}/members/{member}
     * PUT/PATCH /api/v1/members/{member}
     */
    public function update(Request $request, ...$args)
    {
        $member = end($args);
        $mandalScope = count($args) >= 2 ? $args[count($args) - 2] : null;
        $memberRecord = MandalMember::with('user')->find($member);

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found', 404);
        }

        if ($mandalScope !== null && (string) $memberRecord->mandal_id !== (string) $mandalScope) {
            return $this->error('NOT_FOUND', 'Member not found in this mandal', 404);
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

        if ($request->has('phone') && $request->input('phone') !== null && $request->input('phone') !== '') {
            $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
            if (strlen($digits) >= 10) {
                $request->merge(['phone' => substr($digits, -10)]);
            }
        }

        $validated = $request->validate([
            'fullName' => ['nullable', 'string', 'min:2', 'max:80'],
            'name' => ['nullable', 'string', 'min:2', 'max:80'],
            'phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'role' => ['nullable', 'in:ADMIN,TREASURER,COLLECTOR,MEMBER'],
            'area' => ['nullable', 'string', 'max:255'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $newFullName = $validated['fullName'] ?? $validated['name'] ?? null;
        $newPhone = $validated['phone'] ?? null;
        $newRole = $validated['role'] ?? null;
        $newIsActive = array_key_exists('isActive', $validated) ? $validated['isActive'] : null;

        if ($newPhone !== null) {
            $mandalModel = Mandal::find($memberRecord->mandal_id);
            if ($mandalModel && $mandalModel->contact_number) {
                $mandalContact = substr(preg_replace('/\D/', '', (string) $mandalModel->contact_number), -10);
                $memberPhone = substr(preg_replace('/\D/', '', (string) $newPhone), -10);
                if ($mandalContact !== '' && $mandalContact === $memberPhone) {
                    return $this->error(
                        'VALIDATION_FAILED',
                        'Member mobile number cannot be the same as the Mandal registered contact number',
                        422,
                        [['field' => 'phone', 'issue' => 'Member mobile number cannot be the same as the Mandal registered contact number']]
                    );
                }
            }

            if ($memberRecord->user && $newPhone !== $memberRecord->user->phone) {
                $phoneTaken = User::where('phone', $newPhone)
                    ->where('id', '!=', $memberRecord->user_id)
                    ->exists();
                if ($phoneTaken) {
                    return $this->error('VALIDATION_FAILED', 'This phone number is already in use', 422);
                }
            }
        }

        $targetRole = $newRole !== null ? MemberRole::from($newRole) : $memberRecord->role;
        $targetActive = $newIsActive ?? $memberRecord->is_active;
        $isCurrentlyAdmin = in_array($memberRecord->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true);
        $willBeAdmin = in_array($targetRole, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
            || ($newRole === null && $isCurrentlyAdmin);

        // Demoting the last ADMIN (or deactivating them) must be blocked.
        if ($isCurrentlyAdmin && (! $willBeAdmin || $targetActive === false)) {
            $adminCount = MandalMember::where('mandal_id', $memberRecord->mandal_id)
                ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
                ->where('is_active', true)
                ->count();

            if ($adminCount <= 1) {
                $message = $targetActive === false && $willBeAdmin
                    ? 'Cannot deactivate the last ADMIN'
                    : 'Cannot demote the last ADMIN';
                return $this->error('VALIDATION_FAILED', $message, 422);
            }
        }

        try {
            DB::transaction(function () use ($memberRecord, $newFullName, $newPhone, $newRole, $validated, $newIsActive) {
                if ($newRole !== null) {
                    $memberRecord->role = MemberRole::from($newRole);
                }

                if (array_key_exists('area', $validated)) {
                    $memberRecord->area = $validated['area'] ?: null;
                }

                if ($newIsActive !== null) {
                    $memberRecord->is_active = (bool) $newIsActive;
                }

                if ($memberRecord->isDirty()) {
                    $memberRecord->save();
                }

                $memberUser = $memberRecord->user;
                if ($memberUser && ($newFullName !== null || $newPhone !== null)) {
                    $oldPhone = $memberUser->phone;
                    if ($newFullName !== null) {
                        $memberUser->full_name = trim($newFullName);
                    }
                    if ($newPhone !== null) {
                        $memberUser->phone = $newPhone;
                        // Keep username login working when it mirrors the phone.
                        if ($memberUser->username === null || $memberUser->username === $oldPhone) {
                            $memberUser->username = $newPhone;
                        }
                    }
                    if ($memberUser->isDirty()) {
                        $memberUser->save();
                    }
                    $memberRecord->setRelation('user', $memberUser->fresh());
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        $memberRecord->refresh()->load('user');

        CacheKeyService::clearMembers($memberRecord->mandal_id);

        return $this->success($this->memberPayload($memberRecord), 'Member updated successfully');
    }

    /**
     * GET /api/v1/mandals/{mandal}/members/{member}/financial-summary
     */
    public function financialSummary(Request $request, $mandal, $member)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::SUPER_ADMIN, MemberRole::ADMIN, MemberRole::TREASURER])
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

        $festivalId = $request->query('festivalId');
        $scopeFestivalId = null;
        if ($festivalId && Festival::where('id', $festivalId)->where('mandal_id', $mandal)->exists()) {
            $scopeFestivalId = $festivalId;
        }

        $paramsHash = CacheKeyService::paramsHash(['festivalId' => $scopeFestivalId]);
        $cacheKey = CacheKeyService::memberSummary($mandal, $memberRecord->id, $paramsHash);

        $summary = CacheKeyService::remember(
            $cacheKey,
            CacheKeyService::TTL_MEMBER_SUMMARY,
            function () use ($mandal, $userId, $member, $scopeFestivalId) {
                $varganiQuery = VarganiEntry::where('mandal_id', $mandal)
                    ->where('collector_id', $userId)
                    ->where('is_cancelled', false);
                $expenseQuery = ExpenseEntry::whereHas('festival', function ($q) use ($mandal) {
                    $q->where('mandal_id', $mandal);
                })
                    ->where('created_by_user_id', $userId);
                $handoverQuery = CashHandover::whereHas('festival', function ($q) use ($mandal) {
                    $q->where('mandal_id', $mandal);
                })
                    ->where('from_user_id', $userId)
                    ->where('status', HandoverStatus::VERIFIED_ACCEPTED);
                $bookQuery = ReceiptBook::whereHas('festival', function ($q) use ($mandal) {
                    $q->where('mandal_id', $mandal);
                })
                    ->where('assigned_to_user_id', $userId)
                    ->where('status', ReceiptBookStatus::ACTIVE);

                if ($scopeFestivalId) {
                    $varganiQuery->where('festival_id', $scopeFestivalId);
                    $expenseQuery->where('festival_id', $scopeFestivalId);
                    $handoverQuery->where('festival_id', $scopeFestivalId);
                    $bookQuery->where('festival_id', $scopeFestivalId);
                }

                $totalVargani = (float) (clone $varganiQuery)->sum('amount');
                $cashAmount = (float) (clone $varganiQuery)->where('payment_mode', PaymentMode::CASH->value)->sum('amount');
                $upiAmount = (float) (clone $varganiQuery)->where('payment_mode', PaymentMode::UPI->value)->sum('amount');
                $receiptCount = (clone $varganiQuery)->count();
                $totalExpenses = (float) (clone $expenseQuery)->sum('amount');
                $expenseCount = (clone $expenseQuery)->count();
                $cashSubmitted = (float) (clone $handoverQuery)->sum('amount');
                $cashPending = max($cashAmount - $cashSubmitted, 0);

                $recentCollections = (clone $varganiQuery)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'timeText' => $e->created_at?->format('d M Y, h:i A'),
                        'amount' => (float) $e->amount,
                    ])
                    ->values()
                    ->all();

                $recentHandovers = (clone $handoverQuery)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($h) => [
                        'id' => $h->id,
                        'dateText' => $h->created_at?->format('d M Y'),
                        'amount' => (float) $h->amount,
                    ])
                    ->values()
                    ->all();

                $assignedBook = (clone $bookQuery)->orderByDesc('assigned_date')->first();

                return [
                    'memberId' => (string) $member,
                    'totalVarganiCollected' => $totalVargani,
                    'totalExpensesCreated' => $totalExpenses,
                    'receiptCount' => (int) $receiptCount,
                    'expenseCount' => (int) $expenseCount,
                    'cashAmount' => $cashAmount,
                    'upiAmount' => $upiAmount,
                    'cashSubmitted' => $cashSubmitted,
                    'cashPending' => $cashPending,
                    'assignedBookNumber' => $assignedBook?->book_number,
                    'assignedBookDate' => $assignedBook?->assigned_date?->format('Y-m-d'),
                    'recentCollections' => $recentCollections,
                    'recentHandovers' => $recentHandovers,
                ];
            }
        );

        return $this->success($summary, 'Financial summary retrieved');
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

    /**
     * POST /api/v1/mandals/{mandal}/members/{member}/reactivate
     */
    public function reactivate(Request $request, $mandal, $member)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can reactivate members', 403);
        }

        $memberRecord = MandalMember::with('user')
            ->where('mandal_id', $mandal)
            ->where('id', $member)
            ->first();

        if (! $memberRecord) {
            return $this->error('NOT_FOUND', 'Member not found in this mandal', 404);
        }

        $memberRecord->update(['is_active' => true]);

        CacheKeyService::clearMembers($mandal);

        return $this->success($this->memberPayload($memberRecord->fresh()->load('user')), 'Member reactivated successfully');
    }

    /**
     * POST /api/v1/mandals/{mandal}/members/{member}/reset-login
     *
     * Re-issues a one-time temporary password for the member's user account
     * (ADMIN). The plain password is returned once so the admin can share it
     * with the member; it is stored hashed and cannot be retrieved later.
     */
    public function resetLogin(Request $request, $mandal, $member)
    {
        $user = $request->user();

        $authMembership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $authMembership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can reset member login', 403);
        }

        $memberRecord = MandalMember::with('user')
            ->where('mandal_id', $mandal)
            ->where('id', $member)
            ->first();

        if (! $memberRecord || ! $memberRecord->user) {
            return $this->error('NOT_FOUND', 'Member not found in this mandal', 404);
        }

        $temporaryPassword = self::generateTemporaryPassword();
        $memberRecord->user->password = $temporaryPassword;
        $memberRecord->user->save();

        CacheKeyService::clearMembers($mandal);

        return $this->success([
            'memberId' => $memberRecord->id,
            'username' => $memberRecord->user->username ?? $memberRecord->user->phone,
            'phone' => $memberRecord->user->phone,
            'temporaryPassword' => $temporaryPassword,
        ], 'Login reset. Share these credentials with the member once.');
    }

    private function memberPayload(MandalMember $memberRecord): array
    {
        $fullName = $memberRecord->user->full_name ?? null;

        return [
            'id' => $memberRecord->id,
            'fullName' => $fullName,
            'name' => $fullName,
            'phone' => $memberRecord->user->phone ?? null,
            'username' => $memberRecord->user->username ?? $memberRecord->user->phone ?? null,
            'role' => $memberRecord->role->value,
            'area' => $memberRecord->area,
            'isActive' => $memberRecord->is_active,
            'isDefault' => $memberRecord->is_default,
            'joinedAt' => $memberRecord->joined_at,
        ];
    }

    private static function generateTemporaryPassword(): string
    {
        return 'Mh'.strtoupper(Str::random(2)).random_int(10, 99).Str::lower(Str::random(3));
    }
}
