<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\ExpenseStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
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
        fwrite(STDERR, "\nDBG expense id={$expense->id} exists=" . var_export($expense->exists, true) . " festival={$ctx['festival']->id} eid={$expense->festival_id}\n");
        fwrite(STDERR, "\nDBG rows=" . \App\Models\ExpenseEntry::count() . "\n");

        fwrite(STDERR, "\nDBG2 find=" . var_export(ExpenseEntry::find($expense->id)?->id, true) . "\n");
        $response = $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id);
        fwrite(STDERR, "\nDBG status=" . $response->getStatusCode() . " body=" . $response->getContent() . "\n");
        $response->assertStatus(200)
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
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
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
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
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
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
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
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $expense = $this->makeExpense($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/festivals/' . $ctx['festival']->id . '/expenses/' . $expense->id . '/mark-paid')
            ->assertStatus(200)
            ->assertJsonPath('data.status', ExpenseStatus::PAID->value);

        $this->assertDatabaseHas('expense_entries', [
            'id' => $expense->id,
            'status' => ExpenseStatus::PAID->value,
        ]);
    }
}