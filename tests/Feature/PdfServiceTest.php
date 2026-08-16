<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\PaymentMode;
use App\Enums\VarganiReceiptType;
use App\Models\FinalHisabAudit;
use App\Models\VarganiEntry;
use App\Services\PdfService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class PdfServiceTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/receipts/*/*.pdf')) as $file) {
            @unlink($file);
        }
        foreach (glob(storage_path('app/reports/*.pdf')) as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_receipt_pdf_is_generated(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $entry = VarganiEntry::create([
            'festival_id' => $ctx['festival']->id,
            'mandal_id' => $ctx['mandal']->id,
            'receipt_number' => '0001',
            'donor_name' => 'Suresh Patil',
            'mobile_number' => '9876543210',
            'amount' => 1500,
            'payment_mode' => PaymentMode::CASH,
            'area' => 'Dhanori',
            'collector_id' => $ctx['user']->id,
            'receipt_type' => VarganiReceiptType::DIGITAL,
        ]);

        $path = app(PdfService::class)->generateReceiptPdf($entry);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));
    }

    public function test_final_hisab_pdf_is_generated(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $audit = FinalHisabAudit::create([
            'festival_id' => $ctx['festival']->id,
            'opening_balance' => 100,
            'vargani_total' => 5000,
            'other_income_total' => 200,
            'total_income' => 5200,
            'total_expenses' => 1200,
            'closing_balance' => 4100,
            'president_signed' => true,
            'treasurer_signed' => false,
            'is_locked' => false,
        ]);

        $path = app(PdfService::class)->generateFinalHisabPdf($ctx['festival']->id, $audit);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));
    }

    public function test_report_service_final_hisab_pdf_is_generated(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $path = app(ReportService::class)->generateFinalHisabPdf($ctx['festival']->id);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));
    }
}
