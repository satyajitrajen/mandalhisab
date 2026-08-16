<?php

namespace App\Services;

use App\Models\ExpenseEntry;
use App\Models\VarganiEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncService
{
    /**
     * Push a batch of vargani and expense entries from a client.
     */
    public function batchPush(string $clientId, string $festivalId, array $batch, ?string $lastSyncedAt): array
    {
        $accepted = [];
        $conflicts = [];

        DB::transaction(function () use ($festivalId, $batch, &$accepted, &$conflicts) {
            // --- Vargani entries ---
            foreach ($batch['varganiEntries'] ?? [] as $entry) {
                $existing = VarganiEntry::where('client_uuid', $entry['client_uuid'])->first();

                if ($existing) {
                    $accepted[] = [
                        'client_uuid' => $entry['client_uuid'],
                        'server_id' => $existing->id,
                        'status' => 'existing',
                    ];
                    continue;
                }

                // Receipt number conflict detection
                if (! empty($entry['receipt_number'])) {
                    $conflict = VarganiEntry::where('festival_id', $festivalId)
                        ->where('receipt_number', $entry['receipt_number'])
                        ->first();

                    if ($conflict) {
                        $conflicts[] = [
                            'client_uuid' => $entry['client_uuid'],
                            'field' => 'receipt_number',
                            'resolution_hint' => 'REASSIGN_NUMBER',
                        ];
                        continue;
                    }
                }

                $created = VarganiEntry::create([
                    'festival_id' => $festivalId,
                    'mandal_id' => $entry['mandal_id'] ?? null,
                    'receipt_number' => $entry['receipt_number'] ?? null,
                    'donor_name' => $entry['donor_name'],
                    'mobile_number' => $entry['mobile_number'] ?? null,
                    'amount' => $entry['amount'],
                    'payment_mode' => $entry['payment_mode'],
                    'area' => $entry['area'] ?? null,
                    'address' => $entry['address'] ?? null,
                    'collector_id' => $entry['collector_id'] ?? null,
                    'receipt_type' => $entry['receipt_type'],
                    'receipt_book_id' => $entry['receipt_book_id'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'client_uuid' => $entry['client_uuid'],
                ]);

                $accepted[] = [
                    'client_uuid' => $entry['client_uuid'],
                    'server_id' => $created->id,
                    'status' => 'created',
                ];
            }

            // --- Expenses ---
            foreach ($batch['expenses'] ?? [] as $expense) {
                $existing = ExpenseEntry::where('client_uuid', $expense['client_uuid'])->first();

                if ($existing) {
                    $accepted[] = [
                        'client_uuid' => $expense['client_uuid'],
                        'server_id' => $existing->id,
                        'status' => 'existing',
                    ];
                    continue;
                }

                if (! empty($expense['bill_base64'])) {
                    $expense['bill_url'] = $this->storeBase64Bill($festivalId, $expense['bill_base64']);
                }

                $created = ExpenseEntry::create([
                    'festival_id' => $festivalId,
                    'title' => $expense['title'],
                    'category' => $expense['category'],
                    'amount' => $expense['amount'],
                    'payment_mode' => $expense['payment_mode'],
                    'paid_to' => $expense['paid_to'] ?? null,
                    'date' => $expense['date'] ?? now()->toDateString(),
                    'status' => $expense['status'] ?? 'PAID',
                    'bill_url' => $expense['bill_url'] ?? null,
                    'bill_pending_reason' => $expense['bill_pending_reason'] ?? null,
                    'notes' => $expense['notes'] ?? null,
                    'created_by_user_id' => $expense['created_by_user_id'] ?? null,
                    'client_uuid' => $expense['client_uuid'],
                ]);

                $accepted[] = [
                    'client_uuid' => $expense['client_uuid'],
                    'server_id' => $created->id,
                    'status' => 'created',
                ];
            }
        });

        return [
            'accepted' => $accepted,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Pull records created/updated since a given timestamp, plus cancelled IDs.
     */
    public function pullDelta(string $festivalId, string $since): array
    {
        $sinceDate = \Carbon\Carbon::parse($since);

        $vargani = VarganiEntry::where('festival_id', $festivalId)
            ->where('updated_at', '>=', $sinceDate)
            ->get();

        $expenses = ExpenseEntry::where('festival_id', $festivalId)
            ->where('updated_at', '>=', $sinceDate)
            ->get();

        // Vargani entries can be logically cancelled
        $cancelledIds = VarganiEntry::where('festival_id', $festivalId)
            ->where('is_cancelled', true)
            ->where('updated_at', '>=', $sinceDate)
            ->pluck('id')
            ->toArray();

        return [
            'vargani_entries' => $vargani,
            'expenses' => $expenses,
            'deleted_or_cancelled_ids' => $cancelledIds,
        ];
    }

    protected function storeBase64Bill(string $festivalId, string $base64): string
    {
        $data = base64_decode($base64);
        $ext = 'png';
        $filename = Str::uuid().'.'.$ext;
        $path = "bills/{$festivalId}/{$filename}";

        Storage::disk('local')->put($path, $data);

        return $path;
    }
}
