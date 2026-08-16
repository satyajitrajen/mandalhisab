<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Enums\ReceiptBookStatus;
use App\Models\ReceiptBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class ReceiptBookContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function makeBook(array $ctx, array $overrides = []): ReceiptBook
    {
        return ReceiptBook::create(array_merge([
            'festival_id' => $ctx['festival']->id,
            'book_number' => 'B-15',
            'start_number' => 301,
            'end_number' => 400,
            'status' => ReceiptBookStatus::ACTIVE,
            'used_count' => 0,
            'cancelled_count' => 0,
        ], $overrides));
    }

    public function test_index_returns_books_with_status_filter_and_meta(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeBook($ctx);
        $this->makeBook($ctx, ['book_number' => 'B-16', 'start_number' => 401, 'end_number' => 500, 'status' => ReceiptBookStatus::COMPLETED]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/receipt-books?status=ACTIVE')
            ->assertStatus(200)
            ->assertJsonStructure(['meta' => ['page', 'limit', 'totalRecords', 'totalPages', 'timestamp']])
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_book_by_id(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $book = $this->makeBook($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/receipt-books/' . $book->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.bookNumber', 'B-15');
    }

    public function test_store_rejects_overlapping_ranges(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeBook($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/receipt-books', [
                'bookNumber' => 'B-17',
                'startNumber' => 350,
                'endNumber' => 450,
                'assignedDate' => '2026-08-10',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_store_creates_book(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/receipt-books', [
                'bookNumber' => 'B-20',
                'startNumber' => 501,
                'endNumber' => 600,
                'assignedDate' => '2026-08-10',
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'bookNumber', 'startNumber', 'endNumber']]);

        $this->assertDatabaseHas('receipt_books', [
            'festival_id' => $ctx['festival']->id,
            'book_number' => 'B-20',
        ]);
    }

    public function test_assign_book_to_collector(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $collector = User::factory()->create();
        \App\Models\MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $collector->id,
            'role' => MemberRole::COLLECTOR->value,
            'is_active' => true,
            'is_default' => false,
            'joined_at' => now(),
        ]);
        $book = $this->makeBook($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/receipt-books/' . $book->id . '/assign', [
                'collectorId' => $collector->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('receipt_books', [
            'id' => $book->id,
            'assigned_to_user_id' => $collector->id,
        ]);
    }

    public function test_update_status(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $book = $this->makeBook($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/receipt-books/' . $book->id . '/status', [
                'status' => 'LOST',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('receipt_books', [
            'id' => $book->id,
            'status' => ReceiptBookStatus::LOST->value,
        ]);
    }
}