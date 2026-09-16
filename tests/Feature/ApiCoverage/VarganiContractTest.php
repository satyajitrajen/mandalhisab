<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\User;
use App\Models\VarganiEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class VarganiContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    private function makeVargani(array $ctx, array $overrides = []): VarganiEntry
    {
        $entry = VarganiEntry::create(array_merge([
            'festival_id' => $ctx['festival']->id,
            'mandal_id' => $ctx['mandal']->id,
            'receipt_number' => 100,
            'donor_name' => 'Suresh Deshmukh',
            'mobile_number' => '9876543210',
            'amount' => 5000,
            'payment_mode' => 'UPI',
            'area' => 'Kothrud',
            'collector_id' => $ctx['user']->id,
            'receipt_type' => 'DIGITAL',
            'is_cancelled' => false,
        ], $overrides));

        return $entry;
    }

    /**
     * Add an additional member with the given role to an existing context.
     */
    private function makeMemberOf(array $ctx, string $role): User
    {
        $member = User::factory()->create();

        \App\Models\MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $member->id,
            'role' => $role,
            'is_default' => false,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        return $member;
    }

    public function test_index_returns_list_with_envelope_meta(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeVargani($ctx);
        $this->makeVargani($ctx, ['receipt_number' => 101]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['meta' => ['page', 'limit', 'totalRecords', 'totalPages', 'timestamp']])
            ->assertJsonCount(2, 'data');
    }

    public function test_export_returns_sanitized_csv(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $this->makeVargani($ctx, ['donor_name' => '=HYPERLINK(evil)', 'area' => 'Kothrud']);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->get('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/export')
            ->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=utf-8')
            ->assertSee("'=HYPERLINK(evil)", false);
    }

    public function test_show_returns_single_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $entry = $this->makeVargani($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.donorName', 'Suresh Deshmukh')
            // The receipt must carry the real mandal/festival names, not a
            // client-side placeholder.
            ->assertJsonPath('data.mandalName', 'Test Mandal')
            ->assertJsonPath('data.festivalName', 'Ganesh Utsav 2025');
    }

    public function test_pdf_returns_inline_pdf_stream(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $entry = $this->makeVargani($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->get('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/pdf')
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF', false);
    }

    public function test_cancel_marks_entry_cancelled(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $entry = $this->makeVargani($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/cancel', [
                'reason' => 'Duplicate entry',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $entry->id,
            'is_cancelled' => true,
            'cancelled_by_user_id' => $ctx['user']->id,
        ]);
    }

    public function test_member_cannot_cancel_anothers_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);
        $member = $this->makeMemberOf($ctx, MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($member))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/cancel', [
                'reason' => 'Not mine',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $entry->id,
            'is_cancelled' => false,
        ]);
    }

    public function test_collector_cannot_cancel_anothers_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);
        $otherCollector = $this->makeMemberOf($ctx, MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($otherCollector))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/cancel', [
                'reason' => 'Wrong book',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $entry->id,
            'is_cancelled' => false,
        ]);
    }

    public function test_non_member_cannot_cancel_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);
        $outsider = User::factory()->create();

        $this->withHeaders($this->authHeaders($outsider))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/cancel', [
                'reason' => 'Outsider',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $entry->id,
            'is_cancelled' => false,
        ]);
    }

    public function test_treasurer_can_cancel_anothers_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);
        $treasurer = $this->makeTreasurerOf($ctx);

        $this->withHeaders($this->authHeaders($treasurer))
            ->postJson('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/cancel', [
                'reason' => 'Duplicate entry',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $entry->id,
            'is_cancelled' => true,
            'cancelled_by_user_id' => $treasurer->id,
        ]);
    }

    public function test_member_cannot_upload_signature_to_anothers_entry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);
        $member = $this->makeMemberOf($ctx, MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($member))
            ->post('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/signature', [
                'signatureBase64' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 64)),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_signature_upload_accepts_png(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $entry = $this->makeVargani($ctx);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->post('/api/v1/festivals/' . $ctx['festival']->id . '/vargani/' . $entry->id . '/signature', [
                'signatureBase64' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 64)),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->signature_url);
        $this->assertStringContainsString('/storage/signatures/', $fresh->signature_url);
    }

    public function test_public_receipt_verifies_without_auth(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $entry = $this->makeVargani($ctx);

        $this->getJson('/api/v1/public/receipts/' . $entry->receipt_number)
            ->assertStatus(200)
            ->assertJsonPath('data.donorName', 'Suresh Deshmukh')
            ->assertJsonPath('data.amount', 5000);

        $this->getJson('/api/v1/public/receipts/999999')
            ->assertStatus(404);
    }

    public function test_cash_receipt_cancel_blocked_when_already_handed_over(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);
        $treasurer = $this->makeMemberOf($ctx, 'TREASURER');
        $treasurer->forceFill(['security_pin' => \Illuminate\Support\Facades\Hash::make('1234')])->save();

        $headers = $this->authHeaders($ctx['user']);
        $base = '/api/v1/festivals/' . $ctx['festival']->id;

        $create = fn (float $amount) => $this->withHeaders($headers)
            ->postJson($base . '/vargani', [
                'donorName' => 'Donor ' . $amount,
                'amount' => $amount,
                'paymentMode' => 'CASH',
                'area' => 'Area 1',
                'receiptType' => 'DIGITAL',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $handedOverReceipt = $create(500);
        $keptReceipt = $create(200);

        $handoverId = $this->withHeaders($headers)
            ->postJson($base . '/funds/handovers', ['amount' => 500])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders($treasurer, ['X-Festival-Id' => $ctx['festival']->id]))
            ->postJson('/api/v1/funds/handovers/' . $handoverId . '/verify', [
                'status' => 'VERIFIED_ACCEPTED',
                'pin' => '1234',
            ])
            ->assertStatus(200);

        // Cancelling the handed-over receipt would strand the accepted handover.
        $this->withHeaders($headers)
            ->postJson($base . '/vargani/' . $handedOverReceipt . '/cancel')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseHas('vargani_entries', [
            'id' => $handedOverReceipt,
            'is_cancelled' => false,
        ]);

        // The receipt still backed by the collector's own cash cancels fine.
        $this->withHeaders($headers)
            ->postJson($base . '/vargani/' . $keptReceipt . '/cancel')
            ->assertStatus(200);
    }
}