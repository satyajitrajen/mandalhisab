<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\MandalMember;
use App\Models\VarganiEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_member_list_cache_is_invalidated_after_member_create(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members', [
                'fullName' => 'New Collector',
                'phone' => '9822033445',
                'role' => 'COLLECTOR',
            ])
            ->assertSuccessful();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/members')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_vargani_list_cache_is_invalidated_after_create(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $headers = $this->authHeaders($ctx['user']);
        $url = '/api/v1/festivals/' . $ctx['festival']->id . '/vargani';

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)
            ->postJson($url, [
                'donorName' => 'Suresh Deshmukh',
                'amount' => 500,
                'paymentMode' => 'CASH',
                'area' => 'Kothrud',
                'receiptType' => 'DIGITAL',
            ])
            ->assertSuccessful();

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_receipt_books_list_cache_is_invalidated_after_create(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);
        $headers = $this->authHeaders($ctx['user']);
        $url = '/api/v1/festivals/' . $ctx['festival']->id . '/receipt-books';

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)
            ->postJson($url, [
                'bookNumber' => 'B-20',
                'startNumber' => 501,
                'endNumber' => 600,
                'assignedDate' => '2026-08-10',
            ])
            ->assertSuccessful();

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_member_summary_cache_is_invalidated_after_vargani_create(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $headers = $this->authHeaders($ctx['user']);
        $membership = MandalMember::where('mandal_id', $ctx['mandal']->id)
            ->where('user_id', $ctx['user']->id)
            ->first();
        $url = '/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $membership->id . '/financial-summary';

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.receiptCount', 0);

        $this->withHeaders($headers)
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani', [
                'donorName' => 'Suresh Deshmukh',
                'amount' => 500,
                'paymentMode' => 'CASH',
                'area' => 'Kothrud',
                'receiptType' => 'DIGITAL',
            ])
            ->assertSuccessful();

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.receiptCount', 1);
    }

    public function test_member_summary_serves_cached_result_until_invalidated(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $headers = $this->authHeaders($ctx['user']);
        $membership = MandalMember::where('mandal_id', $ctx['mandal']->id)
            ->where('user_id', $ctx['user']->id)
            ->first();
        $url = '/api/v1/mandals/' . $ctx['mandal']->id . '/members/' . $membership->id . '/financial-summary';

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.receiptCount', 0);

        // Model-level create bypasses HTTP-layer invalidation on purpose:
        // the summary must keep serving the cached value.
        VarganiEntry::create([
            'festival_id' => $ctx['festival']->id,
            'mandal_id' => $ctx['mandal']->id,
            'receipt_number' => 100,
            'donor_name' => 'Cache Test Donor',
            'mobile_number' => '9876543210',
            'amount' => 500,
            'payment_mode' => 'CASH',
            'area' => 'Kothrud',
            'collector_id' => $ctx['user']->id,
            'receipt_type' => 'DIGITAL',
            'is_cancelled' => false,
        ]);

        $this->withHeaders($headers)
            ->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.receiptCount', 0);
    }
}
