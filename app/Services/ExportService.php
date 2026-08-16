<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\ExpenseEntry;
use App\Models\VarganiEntry;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ExportService
{
    /**
     * Export vargani entries to xlsx or csv.
     */
    public function exportVargani(string $festivalId, ExportFormat $format, array $filters = []): string
    {
        $query = VarganiEntry::where('festival_id', $festivalId)
            ->where('is_cancelled', false);

        if (! empty($filters['collector_id'])) {
            $query->where('collector_id', $filters['collector_id']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $rows = $query->get()->toArray();

        return $this->writeFile("exports/vargani_{$festivalId}", $format, $rows);
    }

    /**
     * Export expense entries to xlsx or csv.
     */
    public function exportExpenses(string $festivalId, ExportFormat $format, array $filters = []): string
    {
        $query = ExpenseEntry::where('festival_id', $festivalId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('date', '<=', $filters['to_date']);
        }

        $rows = $query->get()->toArray();

        return $this->writeFile("exports/expenses_{$festivalId}", $format, $rows);
    }

    protected function writeFile(string $baseName, ExportFormat $format, array $rows): string
    {
        $ext = match ($format) {
            ExportFormat::XLSX => 'xlsx',
            ExportFormat::CSV => 'csv',
            default => throw new \InvalidArgumentException('Supported formats: xlsx, csv'),
        };

        $filename = "{$baseName}.{$ext}";
        $path = storage_path("app/{$filename}");

        Storage::disk('local')->makeDirectory(dirname($filename));

        $writer = SimpleExcelWriter::create($path);

        foreach ($rows as $row) {
            $writer->addRow($row);
        }

        $writer->close();

        return $path;
    }
}
