<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\FinalHisabAudit;
use App\Models\VarganiEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class SyncContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_batch_push_vargani_create(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user'], [
            'X-Festival-Id' => $ctx['festival']->id,
            'X-Mandal-Id' => $ctx['mandal']->id,
        ]))->postJson('/api/v1/sync/batch', [
            'payload' => [
                [
                    'type' => 'vargani',
                    'action' => 'create',
                    'clientUuid' => 'uuid-1',
                    'data' => [
                        'donorName' => 'Offline Donor',
                        'amount' => 2000,
                        'paymentMode' => 'CASH',
                        'area' => 'Hadapsar',
                        'collectorName' => 'Sagar',
                        'receiptType' => 'DIGITAL',
                    ],
                ],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('vargani_entries', [
            'festival_id' => $ctx['festival']->id,
            'donor_name' => 'Offline Donor',
            'amount' => 2000,
        ]);
    }

    public function test_batch_push_deduplicates_by_client_uuid(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $headers = [
            'X-Festival-Id' => $ctx['festival']->id,
            'X-Mandal-Id' => $ctx['mandal']->id,
        ];
        $payload = ['payload' => [[
            'type' => 'vargani',
            'action' => 'create',
            'clientUuid' => 'uuid-dedup',
            'data' => [
                'donorName' => 'Duplicate Guard',
                'amount' => 500,
                'paymentMode' => 'UPI',
                'area' => 'Kothrud',
                'collectorName' => 'Sagar',
                'receiptType' => 'DIGITAL',
            ],
        ]]];

        $this->withHeaders($this->authHeaders($ctx['user'], $headers))->postJson('/api/v1/sync/batch', $payload)->assertStatus(200);
        $this->withHeaders($this->authHeaders($ctx['user'], $headers))->postJson('/api/v1/sync/batch', $payload)->assertStatus(200);

        $this->assertSame(1, VarganiEntry::where('festival_id', $ctx['festival']->id)->count());
    }

    public function test_batch_push_rejects_without_payload(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user'], [
            'X-Festival-Id' => $ctx['festival']->id,
            'X-Mandal-Id' => $ctx['mandal']->id,
        ]))->postJson('/api/v1/sync/batch', [])
            ->assertStatus(422);
    }

    public function test_sync_batch_blocked_when_hisab_locked(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

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

        $this->withHeaders($this->authHeaders($ctx['user'], [
            'X-Festival-Id' => $ctx['festival']->id,
            'X-Mandal-Id' => $ctx['mandal']->id,
        ]))->postJson('/api/v1/sync/batch', [
            'payload' => [[
                'type' => 'vargani',
                'action' => 'create',
                'clientUuid' => 'uuid-locked',
                'data' => [
                    'donorName' => 'Blocked',
                    'amount' => 100,
                    'paymentMode' => 'CASH',
                    'area' => 'Kothrud',
                    'collectorName' => 'Sagar',
                    'receiptType' => 'DIGITAL',
                ],
            ]],
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'HISAB_LOCKED');
    }

    public function test_pull_delta_requires_festival_header_and_returns_changes(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        // Missing header → 400.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/sync/pull?lastSyncAt=2026-01-01T00:00:00Z')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');

        $this->withHeaders($this->authHeaders($ctx['user'], [
            'X-Festival-Id' => $ctx['festival']->id,
        ]))->getJson('/api/v1/sync/pull?lastSyncAt=2026-01-01T00:00:00Z')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['vargani', 'expenses', 'cashHandovers', 'otherIncome', 'fundTransfers']]);
    }

    public function test_stream_events_rejects_anonymous(): void
    {
        // The SSE handler boots an infinite stream once authenticated; the
        // contract point that matters here is that unauthenticated access is
        // rejected before streaming starts.
        $this->getJson('/api/v1/stream/events', ['Accept' => 'text/event-stream'])
            ->assertStatus(401);
    }
}