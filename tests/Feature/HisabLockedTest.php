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
}