<?php

namespace Tests\Feature;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Models\CashHandover;
use App\Models\FestivalBalance;
use App\Models\MandalMember;
use App\Models\MoneyTrailEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class FundFlowTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

public function test_handover_submit_does_not_move_balances(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $balance = FestivalBalance::where('festival_id', $collector['festival']->id)->first();
        $balance->cash_collectors = 10000;
        $balance->save();

        $this->withHeaders($this->authHeaders($collector['user']))
            ->postJson('/api/v1/festivals/' . $collector['festival']->id . '/funds/handovers', [
                'amount' => 4000,
                'notes' => 'Evening handover',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('cash_handovers', [
            'festival_id' => $collector['festival']->id,
            'status' => HandoverStatus::PENDING_APPROVAL->value,
        ]);

        // Cash remains with collectors — no trail entry yet
        $this->assertDatabaseCount('money_trail_entries', 0);

        $balance->refresh();
        $this->assertEquals(10000, (float) $balance->cash_collectors);
        $this->assertEquals(0, (float) $balance->cash_treasurer);
    }

public function test_handover_verify_accept_moves_collector_cash_to_treasurer(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($collector);
        $treasurer->forceFill(['security_pin' => Hash::make('1234')])->save();

        $balance = FestivalBalance::where('festival_id', $collector['festival']->id)->first();
        $balance->cash_collectors = 10000;
        $balance->save();

        $handover = CashHandover::create([
            'festival_id' => $collector['festival']->id,
            'from_user_id' => $collector['user']->id,
            'to_user_id' => $treasurer->id,
            'amount' => 4000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

$this->withHeaders($this->authHeaders($treasurer, [
            'X-Festival-Id' => $collector['festival']->id,
        ]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
                'verificationNotes' => 'Counted and accepted',
            ])
            ->assertStatus(200);

        $balance->refresh();
        $this->assertEquals(6000, (float) $balance->cash_collectors);
        $this->assertEquals(4000, (float) $balance->cash_treasurer);

        $this->assertDatabaseHas('money_trail_entries', [
            'festival_id' => $collector['festival']->id,
            'type' => 'CASH_HANDOVER',
            'amount' => 4000,
            'is_positive' => true,
        ]);

        $this->assertDatabaseHas('cash_handovers', [
            'id' => $handover->id,
            'status' => HandoverStatus::VERIFIED_ACCEPTED->value,
        ]);
    }

public function test_handover_reject_does_not_move_balances(): void
    {
        $collector = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($collector);
        $treasurer->forceFill(['security_pin' => Hash::make('1234')])->save();

        $balance = FestivalBalance::where('festival_id', $collector['festival']->id)->first();
        $balance->cash_collectors = 10000;
        $balance->save();

        $handover = CashHandover::create([
            'festival_id' => $collector['festival']->id,
            'from_user_id' => $collector['user']->id,
            'to_user_id' => $treasurer->id,
            'amount' => 4000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

$this->withHeaders($this->authHeaders($treasurer, [
            'X-Festival-Id' => $collector['festival']->id,
        ]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'REJECTED',
                'pin' => '1234',
                'verificationNotes' => 'Count mismatch',
            ])
            ->assertStatus(200);

        $balance->refresh();
        $this->assertEquals(10000, (float) $balance->cash_collectors);
        $this->assertEquals(0, (float) $balance->cash_treasurer);
        $this->assertDatabaseCount('money_trail_entries', 0);
    }

    public function test_transfer_rejects_when_source_insufficient(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $balance->cash_treasurer = 1000;
        $balance->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/transfers', [
                'fromBucket' => 'CASH_TREASURER',
                'toBucket' => 'BANK',
                'amount' => 5000,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('fund_transfers', 0);
        $this->assertDatabaseCount('money_trail_entries', 0);
    }

    public function test_transfer_succeeds_and_moves_buckets(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $balance->cash_treasurer = 10000;
        $balance->bank = 5000;
        $balance->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/transfers', [
                'fromBucket' => 'CASH_TREASURER',
                'toBucket' => 'BANK',
                'amount' => 3000,
                'notes' => 'Bank deposit',
            ])
            ->assertStatus(200);

        $balance->refresh();
        $this->assertEquals(7000, (float) $balance->cash_treasurer);
        $this->assertEquals(8000, (float) $balance->bank);

        $this->assertDatabaseHas('fund_transfers', [
            'festival_id' => $ctx['festival']->id,
            'from_bucket' => 'CASH_TREASURER',
            'to_bucket' => 'BANK',
            'amount' => 3000,
        ]);

        $this->assertDatabaseHas('money_trail_entries', [
            'festival_id' => $ctx['festival']->id,
            'type' => 'FUND_TRANSFER',
            'amount' => 3000,
        ]);
    }

    public function test_summary_reads_from_ledger(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $balance->cash_treasurer = 1234.50;
        $balance->cash_collectors = 5678.25;
        $balance->bank = 9000;
        $balance->upi = 111;
        $balance->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.cashTreasurer', 1234.50)
            ->assertJsonPath('data.cashCollectors', 5678.25)
            ->assertJsonPath('data.bankBalance', 9000)
            ->assertJsonPath('data.upiBalance', 111);
    }

    public function test_collector_cannot_read_summary(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/summary')
            ->assertStatus(403);
    }
}
