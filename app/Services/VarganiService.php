<?php

namespace App\Services;

use App\Enums\MoneyTrailType;
use App\Enums\PaymentMode;
use App\Enums\VarganiReceiptType;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use App\Models\ReceiptBook;
use App\Models\ReceiptSequence;
use App\Models\VarganiEntry;
use Illuminate\Support\Facades\DB;

class VarganiService
{
    /**
     * Create a vargani entry inside a transaction with atomic receipt number generation.
     */
    public function createVargani(string $festivalId, array $data, string $collectorId): VarganiEntry
    {
        return DB::transaction(function () use ($festivalId, $data, $collectorId) {
            $festival = Festival::findOrFail($festivalId);

            $receiptType = VarganiReceiptType::from($data['receipt_type']);

            if ($receiptType === VarganiReceiptType::PHYSICAL_BOOK) {
                if (empty($data['receipt_book_id'])) {
                    throw new \InvalidArgumentException('Receipt book ID is required for physical book entries.');
                }

                $book = ReceiptBook::where('id', $data['receipt_book_id'])
                    ->where('festival_id', $festivalId)
                    ->first();

                if (! $book) {
                    throw new \InvalidArgumentException('Invalid receipt book for this festival.');
                }

                if (in_array($book->status->value, ['CANCELLED', 'LOST'], true)) {
                    throw new \InvalidArgumentException('Receipt book is ' . $book->status->value);
                }
            }

            $receiptNumber = ReceiptSequence::nextForFestival($festivalId);

            $entry = VarganiEntry::create([
                'festival_id' => $festivalId,
                'mandal_id' => $festival->mandal_id,
                'receipt_number' => $receiptNumber,
                'donor_name' => $data['donor_name'],
                'mobile_number' => $data['mobile_number'] ?? null,
                'amount' => $data['amount'],
                'payment_mode' => PaymentMode::from($data['payment_mode']),
                'area' => $data['area'] ?? null,
                'address' => $data['address'] ?? null,
                'collector_id' => $collectorId,
                'receipt_type' => $receiptType,
                'receipt_book_id' => $data['receipt_book_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            // Update festival balance ledger
            $balance = FestivalBalance::forFestival($festivalId);
            $bucket = match ($entry->payment_mode) {
                PaymentMode::CASH => 'cash_collectors',
                PaymentMode::UPI => 'upi',
                PaymentMode::CHEQUE, PaymentMode::NET_BANKING => 'bank',
            };
            $balance->addToBucket($bucket, (float) $entry->amount);

            // Maintain immutable audit trail
            $this->createMoneyTrailForVargani($entry);

            return $entry;
        });
    }

    protected function createMoneyTrailForVargani(VarganiEntry $entry): void
    {
        $type = match ($entry->payment_mode) {
            PaymentMode::CASH => MoneyTrailType::CASH_RECEIVED,
            PaymentMode::UPI => MoneyTrailType::UPI_RECEIVED,
            default => MoneyTrailType::CASH_RECEIVED,
        };

        MoneyTrailEntry::create([
            'festival_id' => $entry->festival_id,
            'type' => $type,
            'title' => 'Vargani Receipt #'.$entry->receipt_number,
            'subtitle' => $entry->donor_name,
            'amount' => $entry->amount,
            'is_positive' => true,
            'reference_id' => $entry->id,
            'reference_type' => VarganiEntry::class,
        ]);
    }
}
