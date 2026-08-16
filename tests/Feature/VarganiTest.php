<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\MoneyTrailType;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use App\Models\VarganiEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class VarganiTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function varganiPayload(array $overrides = []): array
    {
        return array_merge([
            'donorName' => 'Ramesh Patil',
            'mobileNumber' => '9876543210',
            'amount' => 1000,
            'paymentMode' => 'CASH',
            'area' => 'Kothrud',
            'receiptType' => 'DIGITAL',
        ], $overrides);
    }

    public function test_collector_can_create_vargani(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'receiptNumber']]);

        $this->assertDatabaseHas('vargani_entries', [
            'festival_id' => $ctx['festival']->id,
            'donor_name' => 'Ramesh Patil',
            'amount' => 1000,
        ]);
    }

    public function test_admin_can_create_vargani(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(201);
    }

    public function test_member_cannot_create_vargani(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(403);
    }

    public function test_non_member_cannot_create_vargani(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $outsider = \App\Models\User::factory()->create();

        $this->withHeaders($this->authHeaders($outsider))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(403);
    }

    public function test_vargani_creation_updates_balance_and_money_trail(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload([
                'amount' => 2500,
            ]))
            ->assertStatus(201);

        $balance = FestivalBalance::where('festival_id', $ctx['festival']->id)->first();
        $this->assertEquals(2500, (float) $balance->cash_collectors);

        $this->assertDatabaseHas('money_trail_entries', [
            'festival_id' => $ctx['festival']->id,
            'type' => MoneyTrailType::CASH_RECEIVED->value,
            'amount' => 2500,
            'is_positive' => true,
        ]);
    }

    public function test_vargani_generates_receipt_sequence(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(201);
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', $this->varganiPayload())
            ->assertStatus(201);

        $numbers = VarganiEntry::where('festival_id', $ctx['festival']->id)
            ->orderBy('receipt_number')
            ->pluck('receipt_number');

        $this->assertCount(2, $numbers);
        $this->assertEquals([1, 2], $numbers->map(fn ($n) => (int) $n)->all());
    }
}