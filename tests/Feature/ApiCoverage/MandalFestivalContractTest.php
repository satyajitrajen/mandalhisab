<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\Mandal;
use App\Models\Festival;
use App\Models\MandalMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class MandalFestivalContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_index_mandals_returns_only_memberships(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $outsider = User::factory()->create();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($outsider))
            ->getJson('/api/v1/mandals')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_store_mandal_creates_mandal_and_admin_membership(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/mandals', [
                'name' => 'New Test Mandal',
                'address' => 'Survey No 25',
                'city' => 'Pune',
                'pincode' => '411038',
                'contactNumber' => '9876501234',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'name']]);

        $mandalId = $this->getLastMandalId();
        $this->assertDatabaseHas('mandal_members', [
            'mandal_id' => $mandalId,
            'user_id' => $user->id,
            'role' => MemberRole::ADMIN->value,
            'is_default' => true,
        ]);
    }

    public function test_show_mandal_allowed_for_member_denied_for_non_member(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $outsider = User::factory()->create();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $ctx['mandal']->id);

        $this->withHeaders($this->authHeaders($outsider))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id)
            ->assertStatus(403);
    }

    public function test_update_mandal_admin_only_and_super_admin_allowed(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $memberCtx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/mandals/' . $ctx['mandal']->id, [
                'name' => 'Renamed Mandal',
                'city' => 'Mumbai',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Mandal');

        $this->withHeaders($this->authHeaders($memberCtx['user']))
            ->putJson('/api/v1/mandals/' . $ctx['mandal']->id, ['name' => 'Hijack'])
            ->assertStatus(403);

        // SUPER_ADMIN membership must pass the admin gate (GAP-2 regression guard).
        $superCtx = $this->makeFestivalContext(MemberRole::SUPER_ADMIN->value);
        $this->withHeaders($this->authHeaders($superCtx['user']))
            ->putJson('/api/v1/mandals/' . $superCtx['mandal']->id, ['city' => 'Nashik'])
            ->assertStatus(200);
    }

    public function test_destroy_mandal_admin_only_with_second_admin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $secondAdmin = User::factory()->create();
        MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $secondAdmin->id,
            'role' => MemberRole::ADMIN->value,
            'is_active' => true,
            'is_default' => false,
            'joined_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->deleteJson('/api/v1/mandals/' . $ctx['mandal']->id)
            ->assertStatus(200);

        // Soft delete: row archived, not removed (audit trail preserved).
        $this->assertSoftDeleted('mandals', ['id' => $ctx['mandal']->id]);
        $this->assertDatabaseHas('mandals', ['id' => $ctx['mandal']->id]);
    }

    public function test_destroy_mandal_denied_for_treasurer(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->deleteJson('/api/v1/mandals/' . $ctx['mandal']->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('mandals', ['id' => $ctx['mandal']->id]);
    }

    public function test_show_festival_returns_details(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $ctx['festival']->id)
            ->assertJsonPath('data.mandalId', $ctx['mandal']->id);
    }

    public function test_update_festival_admin_all_fields_treasurer_budget_only(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/festivals/' . $ctx['festival']->id, [
                'name' => 'Ganesh Utsav 2026',
                'budgetGoal' => 250000,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Ganesh Utsav 2026');

        $treasurerCtx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        // Treasurer may update budget only.
        $this->withHeaders($this->authHeaders($treasurerCtx['user']))
            ->putJson('/api/v1/festivals/' . $treasurerCtx['festival']->id, [
                'budgetGoal' => 300000,
            ])
            ->assertStatus(200);

        // Treasurer must NOT rename: ignored silently (PATCH semantics), name stays intact.
        $this->withHeaders($this->authHeaders($treasurerCtx['user']))
            ->putJson('/api/v1/festivals/' . $treasurerCtx['festival']->id, [
                'name' => 'Renamed by Treasurer',
            ])
            ->assertStatus(200);

        $this->assertNotEquals('Renamed by Treasurer', Festival::find($treasurerCtx['festival']->id)->name);
    }

    public function test_dashboard_summary_returns_aggregates_for_member(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/dashboard/summary')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'festival' => ['id', 'name', 'status'],
                    'metrics' => [
                        'totalCollected', 'budgetGoal', 'progressPercentage', 'totalExpenses',
                        'netBalance', 'cashInHand', 'cashTreasurer', 'cashCollectors',
                        'bankBalance', 'upiBalance', 'totalDonorsCount', 'totalReceiptsIssued',
                        'pendingCashWithCollectors',
                    ],
                    'recentTransactions' => [],
                ],
            ]);
    }

    private function getLastMandalId(): string
    {
        return Mandal::latest('id')->value('id');
    }
}