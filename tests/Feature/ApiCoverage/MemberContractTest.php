<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\MandalMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class MemberContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function addMemberTo(array $ctx, string $role = 'COLLECTOR', ?User $user = null): MandalMember
    {
        $user = $user ?? User::factory()->create();

        return MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
            'is_default' => false,
            'joined_at' => now(),
        ]);
    }

    public function test_index_returns_members_with_search_and_role_filter(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $collector = $this->addMemberTo($ctx, 'COLLECTOR');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members?role=COLLECTOR')
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members?search=' . urlencode($collector->user->full_name))
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_member(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $member = $this->addMemberTo($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/members/' . $member->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $member->id);
    }

    public function test_store_creates_member_and_auto_creates_user(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members', [
                'fullName' => 'New Collector',
                'phone' => '9823001122',
                'role' => 'COLLECTOR',
                'area' => 'Warje',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['phone' => '9823001122']);
        $this->assertDatabaseHas('mandal_members', [
            'mandal_id' => $ctx['mandal']->id,
            'role' => MemberRole::COLLECTOR->value,
        ]);
    }

    public function test_store_denied_for_treasurer_and_super_admin_allowed(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members', [
                'fullName' => 'T',
                'phone' => '9823003344',
                'role' => 'MEMBER',
            ])
            ->assertStatus(403);

        // SUPER_ADMIN must pass the admin gate (GAP-3 regression guard).
        $superCtx = $this->makeFestivalContext(MemberRole::SUPER_ADMIN->value);
        $this->withHeaders($this->authHeaders($superCtx['user']))
            ->postJson('/api/v1/mandals/' . $superCtx['mandal']->id . '/members', [
                'fullName' => 'Super',
                'phone' => '9823005566',
                'role' => 'MEMBER',
            ])
            ->assertStatus(201);
    }

    public function test_update_changes_role_and_guards_last_admin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $secondAdmin = $this->addMemberTo($ctx, 'ADMIN');

        // Two admins — demote one.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/members/' . $secondAdmin->id, [
                'role' => 'COLLECTOR',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'COLLECTOR');

        // Last admin must not be demotable.
        $singleCtx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $this->withHeaders($this->authHeaders($singleCtx['user']))
            ->putJson('/api/v1/members/' . $this->adminMembershipId($singleCtx), [
                'role' => 'MEMBER',
            ])
            ->assertStatus(422);
    }

    public function test_financial_summary_admin_treasurer_only(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $member = $this->addMemberTo($ctx, 'MEMBER');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $member->id . '/financial-summary')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['totalVarganiCollected', 'totalExpensesCreated', 'receiptCount', 'expenseCount']]);

        $collectorCtx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->withHeaders($this->authHeaders($collectorCtx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $member->id . '/financial-summary')
            ->assertStatus(403);
    }

    public function test_deactivate_removes_member(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $extra = $this->addMemberTo($ctx, 'COLLECTOR');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $extra->id . '/deactivate')
            ->assertStatus(200);

        $this->assertDatabaseHas('mandal_members', [
            'id' => $extra->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivate_guards_last_admin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $adminMembership = MandalMember::where('user_id', $ctx['user']->id)->first();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $adminMembership->id . '/deactivate')
            ->assertStatus(422);
    }

    private function adminMembershipId(array $ctx): string
    {
        return MandalMember::where('mandal_id', $ctx['mandal']->id)
            ->where('role', MemberRole::ADMIN->value)
            ->where('is_active', true)
            ->first()->id;
    }
}