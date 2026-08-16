<?php

namespace Tests\Support;

use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth as JwtAuthFacade;

trait JwtAuth
{
    /**
     * Generate Authorization headers with a fresh JWT for the given user.
     */
    protected function authHeaders(User $user, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . JwtAuthFacade::fromUser($user),
        ], $extra);
    }

    /**
     * Add a treasurer member to an existing festival context.
     */
    protected function makeTreasurerOf(array $context): User
    {
        $treasurer = User::factory()->create();

        \App\Models\MandalMember::create([
            'mandal_id' => $context['mandal']->id,
            'user_id' => $treasurer->id,
            'role' => 'TREASURER',
            'is_default' => false,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        return $treasurer;
    }

    /**
     * Create a mandal + festival + membership scaffolding for a user.
     */
    protected function makeFestivalContext(string $role, ?User $user = null): array
    {
        $user = $user ?? User::factory()->create();

        $mandal = \App\Models\Mandal::create([
            'name' => 'Test Mandal',
            'address' => '123 Main St',
            'city' => 'Pune',
            'pincode' => '411001',
            'contact_number' => '9876543210',
            'created_by_user_id' => $user->id,
        ]);

        $festival = \App\Models\Festival::create([
            'mandal_id' => $mandal->id,
            'name' => 'Ganesh Utsav',
            'year' => '2025',
            'start_date' => '2025-09-01',
            'end_date' => '2025-09-18',
            'status' => 'ACTIVE',
            'budget_goal' => 0,
            'opening_balance' => 0,
        ]);

        \App\Models\MandalMember::create([
            'mandal_id' => $mandal->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_default' => true,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        \App\Models\FestivalBalance::firstOrCreate(
            ['festival_id' => $festival->id],
            ['cash_treasurer' => 0, 'cash_collectors' => 0, 'bank' => 0, 'upi' => 0, 'version' => 0]
        );

        return [
            'user' => $user,
            'mandal' => $mandal,
            'festival' => $festival,
        ];
    }
}