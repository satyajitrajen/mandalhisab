<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Models\CashHandover;
use App\Models\FestivalBalance;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class DomainNotificationTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_vargani_notifies_treasurer_not_collector(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($collector);

        $this->withHeaders($this->authHeaders($collector['user']))
            ->postJson('/api/v1/festivals/'.$collector['festival']->id.'/vargani', [
                'donorName' => 'Ramesh Patil',
                'mobileNumber' => '9876543210',
                'amount' => 1000,
                'paymentMode' => 'CASH',
                'area' => 'Kothrud',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $treasurer->id)
            ->where('type', NotificationType::VARGANI_CREATED)
            ->count());
        $this->assertSame(0, Notification::query()
            ->where('user_id', $collector['user']->id)
            ->count());
    }

    public function test_expense_notifies_treasurer_not_creator(): void
    {
        $admin = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $treasurer = $this->makeTreasurerOf($admin);

        $this->withHeaders($this->authHeaders($admin['user']))
            ->postJson('/api/v1/festivals/'.$admin['festival']->id.'/expenses', [
                'title' => 'Stage Lighting',
                'category' => 'SOUND_LIGHTING',
                'amount' => 1500,
                'paymentMode' => 'CASH',
                'paidTo' => 'Light Co.',
                'date' => '2026-08-10',
                'status' => 'PENDING',
                'billPendingReason' => 'Awaiting seller invoice',
            ])
            ->assertStatus(201);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $treasurer->id)
            ->where('type', NotificationType::EXPENSE_CREATED)
            ->count());
        $this->assertSame(0, Notification::query()
            ->where('user_id', $admin['user']->id)
            ->where('type', NotificationType::EXPENSE_CREATED)
            ->count());
    }

    public function test_handover_submit_notifies_treasurer(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($collector);
        $this->collectCash($collector, $collector['user'], 4000);
        Notification::query()->delete();

        $this->withHeaders($this->authHeaders($collector['user']))
            ->postJson('/api/v1/festivals/'.$collector['festival']->id.'/funds/handovers', [
                'amount' => 4000,
                'notes' => 'Evening handover',
            ])
            ->assertStatus(201);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $treasurer->id)
            ->where('type', NotificationType::HANDOVER_INITIATED)
            ->count());
        $this->assertSame(0, Notification::query()
            ->where('user_id', $collector['user']->id)
            ->count());
    }

    public function test_handover_accept_notifies_collector(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($collector);
        $treasurer->forceFill(['security_pin' => Hash::make('1234')])->save();

        $this->withHeaders($this->authHeaders($collector['user']))
            ->postJson('/api/v1/festivals/'.$collector['festival']->id.'/vargani', [
                'donorName' => 'Cash Donor',
                'amount' => 10000,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);

        $handover = CashHandover::create([
            'festival_id' => $collector['festival']->id,
            'from_user_id' => $collector['user']->id,
            'to_user_id' => $treasurer->id,
            'amount' => 4000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => 'PENDING_APPROVAL',
        ]);

        $this->withHeaders($this->authHeaders($treasurer, [
            'X-Festival-Id' => $collector['festival']->id,
        ]))
            ->postJson('/api/v1/funds/handovers/'.$handover->id.'/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(200);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $collector['user']->id)
            ->where('type', NotificationType::HANDOVER_APPROVED)
            ->count());
    }

    public function test_final_hisab_lock_notifies_members(): void
    {
        $admin = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $treasurer = $this->makeTreasurerOf($admin);

        $this->withHeaders($this->authHeaders($admin['user']))
            ->postJson('/api/v1/festivals/'.$admin['festival']->id.'/reports/final-hisab/sign', [
                'role' => 'PRESIDENT',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(200);

        $this->assertSame(0, Notification::query()
            ->where('type', NotificationType::FINAL_HISAB_SIGNED)
            ->count());

        $this->withHeaders($this->authHeaders($treasurer))
            ->postJson('/api/v1/festivals/'.$admin['festival']->id.'/reports/final-hisab/sign', [
                'role' => 'TREASURER',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.isLocked', true);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $admin['user']->id)
            ->where('type', NotificationType::FINAL_HISAB_SIGNED)
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $treasurer->id)
            ->where('type', NotificationType::FINAL_HISAB_SIGNED)
            ->count());
    }
}
