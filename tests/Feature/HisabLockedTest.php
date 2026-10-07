<?php

namespace Tests\Feature;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\ReceiptBookStatus;
use App\Models\CashHandover;
use App\Models\FinalHisabAudit;
use App\Models\ReceiptBook;
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
        $this->makeTreasurerOf($ctx);
        $this->collectCash($ctx, $ctx['user'], 5000);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/funds/handovers', [
                'amount' => 5000,
            ])
            ->assertStatus(201);
    }

    public function test_receipt_book_status_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $book = ReceiptBook::create([
            'festival_id' => $ctx['festival']->id,
            'book_number' => 'B-99',
            'start_number' => 1,
            'end_number' => 100,
            'status' => ReceiptBookStatus::ACTIVE,
            'used_count' => 0,
            'cancelled_count' => 0,
        ]);

        // No X-Festival-Id header — the lock must still be detected from the book.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/receipt-books/' . $book->id . '/status', [
                'status' => 'LOST',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');

        $this->assertDatabaseHas('receipt_books', [
            'id' => $book->id,
            'status' => ReceiptBookStatus::ACTIVE->value,
        ]);
    }

    public function test_handover_verify_blocked_when_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $handover = CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 3000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handover->id . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');

        $this->assertDatabaseHas('cash_handovers', [
            'id' => $handover->id,
            'status' => HandoverStatus::PENDING_APPROVAL->value,
        ]);
    }

    public function test_unlock_requires_super_admin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/unlock', [
                'pin' => '1234',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('final_hisab_audits', [
            'festival_id' => $ctx['festival']->id,
            'is_locked' => true,
        ]);
    }

    public function test_super_admin_can_unlock_with_pin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::SUPER_ADMIN->value);
        $ctx['user']->forceFill(['security_pin' => \Illuminate\Support\Facades\Hash::make('1234')])->save();
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $headers = $this->authHeaders($ctx['user']);
        $url = '/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/unlock';

        $this->withHeaders($headers)
            ->postJson($url, ['pin' => '9999'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PIN');

        $this->withHeaders($headers)
            ->postJson($url, [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PIN_REQUIRED');

        $this->withHeaders($headers)
            ->postJson($url, ['pin' => '1234'])
            ->assertStatus(200)
            ->assertJsonPath('data.isLocked', false);

        $this->assertDatabaseHas('final_hisab_audits', [
            'festival_id' => $ctx['festival']->id,
            'is_locked' => false,
        ]);

        // After unlocking, financial mutations work again.
        $this->withHeaders($headers)
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Post Unlock Donor',
                'amount' => 100,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201);
    }

    public function test_unlock_rejected_when_not_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::SUPER_ADMIN->value);
        $ctx['user']->forceFill(['security_pin' => \Illuminate\Support\Facades\Hash::make('1234')])->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/unlock', [
                'pin' => '1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_resign_after_unlock_refreshes_frozen_snapshot(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::SUPER_ADMIN->value);
        $ctx['user']->forceFill(['security_pin' => \Illuminate\Support\Facades\Hash::make('1234')])->save();

        $headers = $this->authHeaders($ctx['user']);
        $base = '/api/v1/festivals/' . $ctx['festival']->id;
        $varganiPayload = fn (string $donor, float $amount) => [
            'donorName' => $donor,
            'amount' => $amount,
            'paymentMode' => 'CASH',
            'area' => 'Area 1',
            'receiptType' => 'DIGITAL',
        ];

        $this->withHeaders($headers)
            ->postJson($base . '/vargani', $varganiPayload('First Donor', 500))
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson($base . '/reports/final-hisab/sign', ['role' => 'TREASURER', 'authMethod' => 'PIN'])
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->postJson($base . '/reports/final-hisab/sign', ['role' => 'PRESIDENT', 'authMethod' => 'PIN'])
            ->assertStatus(200);

        $this->assertDatabaseHas('final_hisab_audits', [
            'festival_id' => $ctx['festival']->id,
            'vargani_total' => 500,
            'closing_balance' => 500,
            'is_locked' => true,
        ]);

        $this->withHeaders($headers)
            ->postJson($base . '/reports/final-hisab/unlock', ['pin' => '1234'])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson($base . '/vargani', $varganiPayload('Second Donor', 200))
            ->assertStatus(201);

        // Re-sign after corrections: the snapshot must cover the corrected
        // numbers, not the pre-unlock totals.
        $this->withHeaders($headers)
            ->postJson($base . '/reports/final-hisab/sign', ['role' => 'TREASURER', 'authMethod' => 'PIN'])
            ->assertStatus(200);

        $this->assertDatabaseHas('final_hisab_audits', [
            'festival_id' => $ctx['festival']->id,
            'vargani_total' => 700,
            'total_income' => 700,
            'closing_balance' => 700,
        ]);

        $this->withHeaders($headers)
            ->getJson($base . '/reports/final-hisab')
            ->assertStatus(200)
            ->assertJsonPath('data.varganiTotal', fn ($v) => (float) $v === 700.0);
    }
}