<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\FinalHisabAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class ReportContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_overview_returns_metrics_and_chart(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/overview')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['totalVargani', 'totalExpenses', 'totalOtherIncome', 'netBalance', 'totalDonors', 'totalReceipts', 'totalCash', 'totalUPI', 'totalBank', 'expenseByCategory', 'dailyCollection', 'dailyExpenses'],
            ]);
    }

    public function test_overview_denied_for_collector(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/overview')
            ->assertStatus(403);
    }

    public function test_typed_reports_for_all_supported_types(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        foreach (['vargani_summary', 'expense_summary', 'fund_summary', 'member_activity'] as $type) {
            $this->withHeaders($this->authHeaders($ctx['user']))
                ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/' . $type)
                ->assertStatus(200, "Report type {$type} should be supported");
        }
    }

    public function test_typed_reports_reject_unknown_type(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/nonsense_report')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_REPORT_TYPE');
    }

    public function test_final_hisab_returns_live_computed_statement(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'festivalId', 'openingBalance', 'varganiTotal', 'otherIncomeTotal',
                    'totalIncome', 'totalExpenses', 'closingBalance',
                    'presidentSigned', 'treasurerSigned', 'isLocked',
                ],
            ])
            ->assertJsonPath('data.openingBalance', 0);
    }

    public function test_treasurer_signs_final_hisab(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/sign', [
                'role' => 'TREASURER',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.treasurerSigned', true)
            ->assertJsonPath('data.isLocked', false);
    }

    public function test_president_signs_and_locks_when_both_signed(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        // President (admin) signs first.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/sign', [
                'role' => 'PRESIDENT',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.presidentSigned', true)
            ->assertJsonPath('data.isLocked', false);

        // Same ADMIN may also sign the treasurer slot → locks the festival.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/sign', [
                'role' => 'TREASURER',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.treasurerSigned', true)
            ->assertJsonPath('data.isLocked', true);

        $this->assertDatabaseHas('final_hisab_audits', [
            'festival_id' => $ctx['festival']->id,
            'president_signed' => true,
            'treasurer_signed' => true,
            'is_locked' => true,
        ]);
    }

    public function test_collector_cannot_sign_as_president(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/sign', [
                'role' => 'PRESIDENT',
                'authMethod' => 'PIN',
            ])
            ->assertStatus(403);
    }

    public function test_final_hisab_pdf_returns_pdf(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        FinalHisabAudit::create([
            'festival_id' => $ctx['festival']->id,
            'opening_balance' => 0,
            'vargani_total' => 0,
            'other_income_total' => 0,
            'total_income' => 0,
            'total_expenses' => 0,
            'closing_balance' => 0,
            'president_signed' => true,
            'treasurer_signed' => true,
            'is_locked' => true,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->get('/api/v1/festivals/' . $ctx['festival']->id . '/reports/final-hisab/pdf')
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF', false);
    }
}