<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
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
            ->assertJsonPath('data.donorName', 'Suresh Deshmukh');
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
}