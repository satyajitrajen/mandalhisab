<?php

namespace Tests\Feature;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\ReceiptBookStatus;
use App\Models\BankAccount;
use App\Models\CashHandover;
use App\Models\FinalHisabAudit;
use App\Models\ReceiptBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

/**
 * Shallow routes (/funds/handovers/{handover}, /funds/bank-accounts/{account},
 * /receipt-books/{book}) carry no festival in the path. TenantScope must derive
 * the tenant from the resource itself rather than trusting client headers.
 */
class TenantScopeResourceTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function makeHandover(array $ctx): CashHandover
    {
        return CashHandover::create([
            'festival_id' => $ctx['festival']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $ctx['user']->id,
            'amount' => 3000,
            'linked_entry_ids' => [],
            'linked_entries_count' => 0,
            'status' => HandoverStatus::PENDING_APPROVAL,
        ]);
    }

    private function lockFestival(string $festivalId, string $userId): void
    {
        FinalHisabAudit::create([
            'festival_id' => $festivalId,
            'opening_balance' => 0,
            'vargani_total' => 0,
            'other_income_total' => 0,
            'total_income' => 0,
            'total_expenses' => 0,
            'closing_balance' => 0,
            'president_signed' => true,
            'treasurer_signed' => true,
            'president_signed_at' => now(),
            'treasurer_signed_at' => now(),
            'president_user_id' => $userId,
            'treasurer_user_id' => $userId,
            'is_locked' => true,
        ]);
    }

    public function test_handover_show_works_without_tenant_headers(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $handover = $this->makeHandover($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/funds/handovers/'.$handover->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $handover->id);
    }

    public function test_bank_account_update_works_without_tenant_headers(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $account = BankAccount::create([
            'festival_id' => $ctx['festival']->id,
            'bank_name' => 'SBI',
            'account_number' => '30123456789',
            'ifsc' => 'SBIN0001234',
            'account_type' => 'SAVINGS',
            'balance' => 0,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/funds/bank-accounts/'.$account->id, ['upiId' => 'x@upi'])
            ->assertStatus(200);
    }

    public function test_header_for_another_festival_is_rejected(): void
    {
        // The user is a collector in the handover's mandal but ADMIN of another;
        // pointing the header at the other festival must not satisfy the role check.
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $other = $this->makeFestivalContext(MemberRole::ADMIN->value, $ctx['user']);
        $handover = $this->makeHandover($ctx);

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $other['festival']->id]))
            ->getJson('/api/v1/funds/handovers/'.$handover->id)
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/funds/handovers/'.$handover->id)
            ->assertStatus(403);
    }

    public function test_unknown_resource_returns_404(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/funds/handovers/hnd_doesnotexist')
            ->assertStatus(404);
    }

    public function test_receipt_book_write_blocked_on_locked_festival_even_with_other_header(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $other = $this->makeFestivalContext(MemberRole::ADMIN->value, $ctx['user']);
        $book = ReceiptBook::create([
            'festival_id' => $ctx['festival']->id,
            'book_number' => 'B-1',
            'start_number' => 1,
            'end_number' => 100,
            'status' => ReceiptBookStatus::ACTIVE,
            'used_count' => 0,
            'cancelled_count' => 0,
        ]);
        $this->lockFestival($ctx['festival']->id, $ctx['user']->id);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/receipt-books/'.$book->id.'/status', ['status' => 'LOST'])
            ->assertStatus(409);

        $this->withHeaders($this->authHeaders($ctx['user'], ['X-Festival-Id' => $other['festival']->id]))
            ->patchJson('/api/v1/receipt-books/'.$book->id.'/status', ['status' => 'LOST'])
            ->assertStatus(403);

        $this->assertDatabaseHas('receipt_books', ['id' => $book->id, 'status' => 'ACTIVE']);
    }
}
