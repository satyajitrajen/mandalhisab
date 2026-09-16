<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\ReceiptBookStatus;
use App\Models\CashHandover;
use App\Models\MandalMember;
use App\Models\ReceiptBook;
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

        $response = $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members', [
                'fullName' => 'New Collector',
                'phone' => '9823001122',
                'role' => 'COLLECTOR',
                'area' => 'Warje',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.phone', '9823001122');

        $this->assertNotEmpty($response->json('data.temporaryPassword'));
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

    public function test_update_changes_name_area_and_phone(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $member = $this->addMemberTo($ctx, 'COLLECTOR');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $member->id, [
                'fullName' => 'Corrected Name',
                'area' => 'Kothrud',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.fullName', 'Corrected Name')
            ->assertJsonPath('data.area', 'Kothrud');

        $this->assertDatabaseHas('users', [
            'id' => $member->user_id,
            'full_name' => 'Corrected Name',
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/members/' . $member->id, [
                'phone' => '9823007788',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '9823007788');
    }

    public function test_update_rejects_duplicate_and_invalid_phone(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $first = $this->addMemberTo($ctx, 'COLLECTOR', User::factory()->create(['phone' => '9823001111']));
        $second = $this->addMemberTo($ctx, 'COLLECTOR', User::factory()->create(['phone' => '9823002222']));

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/members/' . $second->id, [
                'phone' => '9823001111',
            ])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/members/' . $second->id, [
                'phone' => '123',
            ])
            ->assertStatus(422);
    }

    public function test_update_supports_deactivate_reactivate_guards_last_admin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $extra = $this->addMemberTo($ctx, 'COLLECTOR');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/members/' . $extra->id, ['isActive' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.isActive', false);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $extra->id . '/reactivate')
            ->assertStatus(200)
            ->assertJsonPath('data.isActive', true);

        // Deactivating the last ADMIN via update must fail.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/members/' . $this->adminMembershipId($ctx), ['isActive' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Cannot deactivate the last ADMIN');
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

    public function test_reset_login_reissues_credentials_that_can_login(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $member = $this->addMemberTo($ctx, 'COLLECTOR', User::factory()->create(['phone' => '9823009999']));

        $response = $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $member->id . '/reset-login')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['username', 'temporaryPassword']]);

        $temp = $response->json('data.temporaryPassword');
        $this->assertNotEmpty($temp);

        // The re-issued password must actually log the member in.
        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9823009999',
            'password' => $temp,
        ])->assertStatus(200);

        // Non-admin cannot reset another member's login.
        $collectorCtx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->withHeaders($this->authHeaders($collectorCtx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $member->id . '/reset-login')
            ->assertStatus(403);
    }

    private function adminMembershipId(array $ctx): string
    {
        return MandalMember::where('mandal_id', $ctx['mandal']->id)
            ->where('role', MemberRole::ADMIN->value)
            ->where('is_active', true)
            ->first()->id;
    }

    public function test_financial_summary_excludes_other_mandal_activity(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $subject = $this->addMemberTo($ctx, 'COLLECTOR');
        // The same user also collects in another mandal; that activity must
        // not leak into this mandal's summary.
        $otherCtx = $this->makeFestivalContext(MemberRole::COLLECTOR->value, $subject->user);

        CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $subject->user_id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 400,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::VERIFIED_ACCEPTED,
        ]);
        ReceiptBook::create([
            'festival_id' => $ctx['festival']->id,
            'book_number' => 'A-1',
            'start_number' => 1,
            'end_number' => 100,
            'assigned_to_user_id' => $subject->user_id,
            'status' => ReceiptBookStatus::ACTIVE,
            'used_count' => 0,
            'cancelled_count' => 0,
        ]);

        CashHandover::create([
            'festival_id' => $otherCtx['festival']->id,
            'from_user_id' => $subject->user_id,
            'to_user_id' => $otherCtx['user']->id,
            'amount' => 9000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::VERIFIED_ACCEPTED,
        ]);
        ReceiptBook::create([
            'festival_id' => $otherCtx['festival']->id,
            'book_number' => 'B-9',
            'start_number' => 1,
            'end_number' => 100,
            'assigned_to_user_id' => $subject->user_id,
            'status' => ReceiptBookStatus::ACTIVE,
            'used_count' => 0,
            'cancelled_count' => 0,
        ]);

        $response = $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $subject->id . '/financial-summary')
            ->assertStatus(200);

        $this->assertEquals(400, $response->json('data.cashSubmitted'));
        $this->assertEquals('A-1', $response->json('data.assignedBookNumber'));
    }
}