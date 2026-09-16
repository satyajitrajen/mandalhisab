<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Models\BankAccount;
use App\Models\CashHandover;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use App\Models\OtherIncome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class FundContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_money_trail_returns_entries_and_respects_type_filter(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        MoneyTrailEntry::create([
            'festival_id' => $ctx['festival']->id,
            'type' => 'CASH_RECEIVED',
            'title' => 'Donation',
            'subtitle' => 'Ramesh',
            'amount' => 1000,
            'is_positive' => true,
        ]);
        MoneyTrailEntry::create([
            'festival_id' => $ctx['festival']->id,
            'type' => 'CASH_EXPENSE',
            'title' => 'Stage',
            'subtitle' => 'Light Co',
            'amount' => 500,
            'is_positive' => false,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/money-trail')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/money-trail?type=CASH_RECEIVED')
            ->assertJsonCount(1, 'data');

        // Regression: query-string page/limit must serialize as ints, not strings.
        $meta = $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/money-trail?page=1&limit=100')
            ->assertStatus(200)
            ->json('meta');

        $this->assertIsInt($meta['currentPage']);
        $this->assertIsInt($meta['perPage']);
        $this->assertIsInt($meta['total']);
        $this->assertIsInt($meta['lastPage']);
    }

    public function test_handovers_index_and_show_treasurer_only(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $handover = CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 3000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->getJson('/api/v1/funds/handovers/' . $handover->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $handover->id);

        $collectorCtx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->withHeaders($this->authHeaders($collectorCtx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers')
            ->assertStatus(403);
    }

    public function test_bank_accounts_index_store_update(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/bank-accounts', [
                'bankName' => 'SBI',
                'accountNumber' => '30123456789',
                'ifscCode' => 'SBIN0001234',
                'accountType' => 'SAVINGS',
                'balance' => 10000,
                'upiId' => 'sbi@upi',
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'bankName']]);

        $account = BankAccount::where('festival_id', $ctx['festival']->id)->first();
        $this->assertNotNull($account);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/bank-accounts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->patchJson('/api/v1/funds/bank-accounts/' . $account->id, [
                'upiId' => 'sbi.updated@upi',
                'isActive' => true,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('bank_accounts', [
            'id' => $account->id,
            'upi_id' => 'sbi.updated@upi',
        ]);
    }

    public function test_bank_account_accepts_fixed_deposit_type(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/bank-accounts', [
                'bankName' => 'HDFC',
                'accountNumber' => '50100234567',
                'ifscCode' => 'HDFC0001234',
                'accountType' => 'FIXED_DEPOSIT',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('bank_accounts', [
            'festival_id' => $ctx['festival']->id,
            'account_type' => 'FIXED_DEPOSIT',
        ]);
    }

    public function test_other_income_index_and_store(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/other-income', [
                'title' => 'Garage Rent',
                'amount' => 5000,
                'date' => '2026-08-09',
                'notes' => 'Monthly rent',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('other_income', [
            'festival_id' => $ctx['festival']->id,
            'title' => 'Garage Rent',
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/other-income')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_other_income_denied_for_collector(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/other-income', [
                'title' => 'Rent',
                'amount' => 1000,
                'date' => '2026-08-09',
            ])
            ->assertStatus(403);
    }

    public function test_verify_handover_requires_valid_pin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $ctx['user']->forceFill(['security_pin' => Hash::make('1234')])->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Cash Donor',
                'amount' => 10000,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);

        $handover = CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 3000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

        // Wrong PIN -> rejected.
        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '9999',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PIN');

        // Missing PIN -> rejected.
        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
            ])
            ->assertStatus(422);

        // Correct PIN -> verified.
        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('cash_handovers', [
            'id' => $handover->id,
            'status' => HandoverStatus::VERIFIED_ACCEPTED->value,
        ]);
    }

    public function test_verify_handover_rejects_amount_above_collector_balance(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $ctx['user']->forceFill(['security_pin' => Hash::make('1234')])->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Cash Donor',
                'amount' => 1000,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);

        $handover = CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 5000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Rejected verify must leave the ledger untouched.
        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $this->assertEquals(1000, (float) $balance->cash_collectors);
        $this->assertEquals(0, (float) $balance->cash_treasurer);
        $this->assertDatabaseHas('cash_handovers', [
            'id' => $handover->id,
            'status' => HandoverStatus::PENDING_APPROVAL->value,
        ]);
    }

    public function test_handover_submit_rejects_parties_outside_mandal(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $outsider = \App\Models\User::factory()->create();

        $url = '/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers';

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson($url, [
                'fromUserId' => $outsider->id,
                'amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson($url, [
                'toUserId' => $outsider->id,
                'amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('cash_handovers', 0);

        // A member of the mandal remains a valid from-party.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson($url, [
                'fromUserId' => $ctx['user']->id,
                'amount' => 1000,
            ])
            ->assertStatus(201);
    }

    public function test_handover_cannot_exceed_collectors_own_cash(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeTreasurerOf($ctx);
        $treasurer->forceFill(['security_pin' => Hash::make('1234')])->save();

        // The ctx collector actually collects 1000 in cash.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Rich Donor',
                'amount' => 1000,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);

        // A second collector with zero collections tries to hand over 500.
        $broke = \App\Models\User::factory()->create();
        \App\Models\MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $broke->id,
            'role' => 'COLLECTOR',
            'is_default' => false,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $handoverId = $this->withHeaders($this->authHeaders($broke))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers', [
                'amount' => 500,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders($treasurer, ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handoverId . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Pool untouched — the pooled 1000 belongs to the first collector.
        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $this->assertEquals(1000, (float) $balance->cash_collectors);
        $this->assertEquals(0, (float) $balance->cash_treasurer);
        $this->assertDatabaseHas('cash_handovers', [
            'id' => $handoverId,
            'status' => HandoverStatus::PENDING_APPROVAL->value,
        ]);

        // The collector who actually holds the cash can still hand over.
        $ownId = $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers', [
                'amount' => 400,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders($treasurer, ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $ownId . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(200);

        $balance->refresh();
        $this->assertEquals(600, (float) $balance->cash_collectors);
        $this->assertEquals(400, (float) $balance->cash_treasurer);
    }
}