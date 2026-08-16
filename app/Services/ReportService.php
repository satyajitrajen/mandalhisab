<?php

namespace App\Services;

use App\Enums\AuthMethod;
use App\Enums\ReportType;
use App\Models\ExpenseEntry;
use App\Models\FinalHisabAudit;
use App\Models\Festival;
use App\Models\MoneyTrailEntry;
use App\Models\OtherIncome;
use App\Models\ReceiptBook;
use App\Models\User;
use App\Models\VarganiEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReportService
{
    /**
     * High-level overview of a festival.
     */
    public function getOverview(string $festivalId): array
    {
        $varganiTotal = VarganiEntry::where('festival_id', $festivalId)
            ->where('is_cancelled', false)
            ->sum('amount');

        $expenseTotal = ExpenseEntry::where('festival_id', $festivalId)
            ->where('status', \App\Enums\ExpenseStatus::PAID)
            ->sum('amount');

        $otherIncomeTotal = OtherIncome::where('festival_id', $festivalId)->sum('amount');

        $festival = Festival::findOrFail($festivalId);

        $totalIncome = (float) $varganiTotal + (float) $otherIncomeTotal;

        return [
            'festival' => $festival,
            'vargani_total' => (float) $varganiTotal,
            'other_income_total' => (float) $otherIncomeTotal,
            'total_income' => $totalIncome,
            'total_expenses' => (float) $expenseTotal,
            'closing_balance' => (float) $festival->opening_balance + $totalIncome - (float) $expenseTotal,
        ];
    }

    /**
     * Generate a typed report with optional filters.
     */
    public function getTypedReport(string $festivalId, ReportType $reportType, array $filters = []): array
    {
        return match ($reportType) {
            ReportType::INCOME_EXPENSE => $this->getOverview($festivalId),

            ReportType::COLLECTIONS => VarganiEntry::where('festival_id', $festivalId)
                ->where('is_cancelled', false)
                ->when($filters['collector_id'] ?? null, fn ($q, $id) => $q->where('collector_id', $id))
                ->when($filters['from_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($filters['to_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->get()
                ->toArray(),

            ReportType::EXPENSES => ExpenseEntry::where('festival_id', $festivalId)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['from_date'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                ->when($filters['to_date'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
                ->get()
                ->toArray(),

            ReportType::COLLECTORS => VarganiEntry::where('festival_id', $festivalId)
                ->where('is_cancelled', false)
                ->selectRaw('collector_id, COUNT(*) as receipt_count, SUM(amount) as total_amount')
                ->groupBy('collector_id')
                ->get()
                ->toArray(),

            ReportType::RECEIPT_BOOKS => ReceiptBook::where('festival_id', $festivalId)
                ->get()
                ->toArray(),

            ReportType::CASH => MoneyTrailEntry::where('festival_id', $festivalId)
                ->whereIn('type', [
                    \App\Enums\MoneyTrailType::CASH_RECEIVED,
                    \App\Enums\MoneyTrailType::CASH_EXPENSE,
                    \App\Enums\MoneyTrailType::CASH_HANDOVER,
                ])
                ->get()
                ->toArray(),

            ReportType::FINAL_HISAB => $this->computeFinalHisab($festivalId)->toArray(),

            default => throw new \InvalidArgumentException('Unsupported report type'),
        };
    }

    /**
     * Compute or refresh the final hisab audit record.
     */
    public function computeFinalHisab(string $festivalId): FinalHisabAudit
    {
        $overview = $this->getOverview($festivalId);

        $audit = FinalHisabAudit::updateOrCreate(
            ['festival_id' => $festivalId],
            [
                'opening_balance' => $overview['festival']->opening_balance,
                'vargani_total' => $overview['vargani_total'],
                'other_income_total' => $overview['other_income_total'],
                'total_income' => $overview['total_income'],
                'total_expenses' => $overview['total_expenses'],
                'closing_balance' => $overview['closing_balance'],
                'is_locked' => false,
            ]
        );

        return $audit;
    }

    /**
     * Sign the final hisab by president or treasurer.
     */
    public function signFinalHisab(
        string $festivalId,
        User $user,
        string $role,
        string $authMethod,
        ?string $signature = null,
        ?string $pin = null,
        ?string $notes = null
    ): FinalHisabAudit {
        $audit = FinalHisabAudit::where('festival_id', $festivalId)->firstOrFail();

        $roleNorm = strtoupper($role);
        if ($roleNorm === 'PRESIDENT') {
            $audit->president_signed = true;
            $audit->president_signed_at = now();
            $audit->president_user_id = $user->id;
        } elseif ($roleNorm === 'TREASURER') {
            if ($pin && ! \Illuminate\Support\Facades\Hash::check($pin, $user->security_pin)) {
                throw new \Exception('Invalid PIN');
            }
            $audit->treasurer_signed = true;
            $audit->treasurer_signed_at = now();
            $audit->treasurer_user_id = $user->id;
            $audit->treasurer_auth_method = $authMethod instanceof AuthMethod ? $authMethod : (AuthMethod::tryFrom($authMethod) ?? AuthMethod::PIN);
        } else {
            throw new \InvalidArgumentException('Invalid role. Must be president or treasurer.');
        }

        if ($audit->president_signed && $audit->treasurer_signed) {
            $audit->is_locked = true;
        }

        $audit->save();

        return $audit;
    }

    /**
     * Generate the final hisab PDF using dompdf.
     */
    public function generateFinalHisabPdf(string $festivalId): string
    {
        $audit = $this->computeFinalHisab($festivalId);

        $pdf = Pdf::loadView('pdf.final_hisab', [
            'festival' => $audit->festival,
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

        $filename = "reports/{$festivalId}_final_hisab.pdf";
        Storage::disk('local')->makeDirectory('reports');
        $path = storage_path("app/{$filename}");
        $pdf->save($path);

        // Save URL reference back to audit
        $audit->pdf_report_url = $filename;
        $audit->save();

        return $path;
    }
}
