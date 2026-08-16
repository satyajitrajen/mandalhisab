<?php

namespace App\Services;

use App\Models\Festival;
use App\Models\FinalHisabAudit;
use App\Models\VarganiEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    /**
     * Generate a PDF receipt for a vargani entry.
     */
    public function generateReceiptPdf(VarganiEntry $varganiEntry): string
    {
        $pdf = Pdf::loadView('pdf.receipt', [
            'entry' => $varganiEntry,
        ]);

        $directory = "receipts/{$varganiEntry->festival_id}";
        Storage::disk('local')->makeDirectory($directory);

        $path = storage_path("app/{$directory}/receipt_{$varganiEntry->receipt_number}.pdf");
        $pdf->save($path);

        return $path;
    }

    /**
     * Generate the final hisab PDF.
     */
    public function generateFinalHisabPdf(string $festivalId, FinalHisabAudit $audit): string
    {
        $festival = Festival::findOrFail($festivalId);

        $pdf = Pdf::loadView('pdf.final_hisab', [
            'festival' => $festival,
            'openingBalance' => (float) $audit->opening_balance,
            'varganiTotal' => (float) $audit->vargani_total,
            'otherIncomeTotal' => (float) $audit->other_income_total,
            'totalIncome' => (float) $audit->total_income,
            'totalExpenses' => (float) $audit->total_expenses,
            'closingBalance' => (float) $audit->closing_balance,
            'presidentSigned' => (bool) $audit->president_signed,
            'treasurerSigned' => (bool) $audit->treasurer_signed,
            'presidentSignedAt' => $audit->president_signed_at,
            'treasurerSignedAt' => $audit->treasurer_signed_at,
            'isLocked' => (bool) $audit->is_locked,
        ]);

        Storage::disk('local')->makeDirectory('reports');

        $path = storage_path("app/reports/{$festivalId}_final_hisab.pdf");
        $pdf->save($path);

        return $path;
    }
}
