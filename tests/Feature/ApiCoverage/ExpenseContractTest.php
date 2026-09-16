<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\ExpenseStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
use App\Models\FestivalBalance;
use App\Models\FinalHisabAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class ExpenseContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function expensePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Stage Lighting',
            'category' => 'SOUND_LIGHTING',
            'amount' => 15000,
            'paymentMode' => 'CASH',
            'paidTo' => 'Light Co.',
            'date' => '2026-08-10',
            'status' => 'PENDING',
            'billPendingReason' => 'Awaiting seller invoice',
        ], $overrides);
    }

    private function makeExpense(array $ctx, array $overrides = []): ExpenseEntry
    {
        return ExpenseEntry::create(array_merge([
            'festival_id' => $ctx['festival']->id,
            'title' => 'Stage Lighting',
            'category' => 'SOUND_LIGHTING',
            'amount' => 15000,
            'payment_mode' => PaymentMode::CASH,
            'paid_to' => 'Light Co.',
            'date' => '2026-08-10',
            'status' => ExpenseStatus::PENDING,
            'created_by_user_id' => $ctx['user']->id,
        ], $overrides));
    }

    public function test_index_returns_expenses_with_meta(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeExpense($ctx);
        $this->makeExpense($ctx, ['title' => 'Murti', 'status' => ExpenseStatus::PAID]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses?status=PAID')
            ->assertStatus(200)
            ->assertJsonStructure(['meta' => ['page', 'limit', 'totalRecords', 'totalPages', 'timestamp']])
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses')
            ->assertJsonCount(2, 'data');
    }

    public function test_show_returns_single_expense(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $expense = $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $expense->id);
    }

    public function test_export_returns_csv(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->get('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/export')
            ->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_store_creates_expense_and_trail(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $this->assertDatabaseMissing('expense_entries', ['title' => 'Prasad' ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses', $this->expensePayload([
                'title' => 'Prasad',
                'category' => 'POOJA_PRASAD',
            ]))
            ->assertStatus(201);
    }

    public function test_update_edits_expense_fields(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $expense = $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id, [
                'amount' => 18000,
                'paidTo' => 'Bright Lights',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.amount', 18000);
    }

    public function test_bill_upload_accepts_pdf(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $expense = $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->post('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id . '/bill', [
                'billFile' => UploadedFile::fake()->create('bill.pdf', 1000, 'application/pdf'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_mark_paid_flips_status(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $expense = $this->makeExpense($ctx);

        // Marking a CASH expense paid debits the treasurer's cash bucket.
        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $balance->cash_treasurer = 20000;
        $balance->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id . '/mark-paid')
            ->assertStatus(200)
            ->assertJsonPath('data.status', ExpenseStatus::PAID->value);

        $this->assertDatabaseHas('expense_entries', [
            'id' => $expense->id,
            'status' => ExpenseStatus::PAID->value,
        ]);
    }

    public function test_member_cannot_create_or_edit_expenses(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $expense = $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses', $this->expensePayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id, [
                'amount' => 18000,
            ])
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id . '/mark-paid')
            ->assertStatus(403);
    }

    public function test_update_rejects_expense_from_another_festival(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        // Same user is treasurer in a second mandal whose festival is locked;
        // referencing it through the unlocked festival path must fail.
        $lockedCtx = $this->makeFestivalContext(MemberRole::TREASURER->value, $ctx['user']);
        FinalHisabAudit::create([
            'festival_id' => $lockedCtx['festival']->id,
            'opening_balance' => 0,
            'vargani_total' => 0,
            'other_income_total' => 0,
            'total_income' => 0,
            'total_expenses' => 0,
            'closing_balance' => 0,
            'is_locked' => true,
        ]);

        $expense = $this->makeExpense($lockedCtx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id, [
                'title' => 'Tampered',
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('expense_entries', [
            'id' => $expense->id,
            'title' => 'Stage Lighting',
        ]);
    }
}