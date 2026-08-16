<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\FinalHisabAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class HisabLockedTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function lockFestival(string $festivalId, string $signedByUserId): void
    {
        FinalHisabAudit::create([
            'festival_id' => $festivalId,
            'opening_balance' => 0,
            'vargani_total' => 50000,
            'other_income_total' => 0,
            'total_income' => 50000,
            'total_expenses' => 30000,
            'closing_balance' => 20000,
            'president_signed' => true,
            'treasurer_signed' => true,
            'president_signed_at' => now(),
            'treasurer_signed_at' => now(),
            'president_user_id' => $signedByUserId,
            'treasurer_user_id' => $signedByUserId,
            'is_locked' => true,
        ]);
    }

    public function test_vargani_mutation_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Test Donor',
                'amount' => 100,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');

        $this->assertDatabaseCount('vargani_entries', 0);
    }

    public function test_expense_mutation_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses', [
                'title' => 'Test Expense',
                'category' => 'MISCELLANEOUS',
                'amount' => 500,
                'paymentMode' => 'CASH',
                'paidTo' => 'Vendor',
                'date' => now()->toDateString(),
                'status' => 'PAID',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');

        $this->assertDatabaseCount('expense_entries', 0);
    }

    public function test_transfer_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/transfers', [
                'fromBucket' => 'CASH_TREASURER',
                'toBucket' => 'BANK',
                'amount' => 1000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');
    }

    public function test_handover_submit_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers', [
                'amount' => 5000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');

        $this->assertDatabaseCount('cash_handovers', 0);
    }

    public function test_reads_still_work_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani')
            ->assertStatus(200);
    }

    public function test_handover_submit_allowed_when_not_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers', [
                'amount' => 5000,
            ])
            ->assertStatus(201);
    }
}