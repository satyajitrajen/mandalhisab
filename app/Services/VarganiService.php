<?php

namespace App\Services;

use App\Enums\HandoverStatus;
use App\Enums\MoneyTrailType;
use App\Enums\PaymentMode;
use App\Enums\VarganiReceiptType;
use App\Models\CashHandover;
use App\Models\Festival;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use App\Models\ReceiptBook;
use App\Models\ReceiptSequence;
use App\Models\VarganiEntry;
use Illuminate\Support\Facades\DB;

class VarganiService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Create a vargani entry inside a transaction with atomic receipt number generation.
     */
    public function createVargani(string $festivalId, array $data, string $collectorId): VarganiEntry
    {
        // Offline replays can resend the same clientUuid after a timeout;
        // return the original entry instead of creating a duplicate.
        if (! empty($data['client_uuid'])) {
            $existing = VarganiEntry::where('festival_id', $festivalId)
                ->where('client_uuid', $data['client_uuid'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $entry = DB::transaction(function () use ($festivalId, $data, $collectorId) {
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

            // Digital receipts use the festival-wide sequence; physical books
            // carry pre-printed numbers that the record must match.
            $receiptNumber = $receiptType === VarganiReceiptType::PHYSICAL_BOOK
                ? $this->nextBookReceiptNumber($book)
                : ReceiptSequence::nextForFestival($festivalId);

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

            // Track physical book usage.
            if ($entry->receipt_book_id) {
                ReceiptBook::where('id', $entry->receipt_book_id)->increment('used_count');
            }

            return $entry;
        });

        $this->notifications->notifyVarganiCreated($entry);

        return $entry;
    }

    /**
     * Cancel a vargani entry and reverse its ledger + audit-trail impact.
     * The original receipt is retained; a negative trail entry records the reversal.
     */
    public function cancelVargani(VarganiEntry $entry, ?string $notes, string $userId): VarganiEntry
    {
        if ($entry->is_cancelled) {
            throw new \InvalidArgumentException('Receipt is already cancelled');
        }

        // Cancelling a cash receipt whose money has already been handed over
        // would drive the collectors' pool negative; the reversal must be
        // settled through the handover flow instead.
        if ($entry->payment_mode === PaymentMode::CASH) {
            $collected = (float) VarganiEntry::where('festival_id', $entry->festival_id)
                ->where('collector_id', $entry->collector_id)
                ->where('is_cancelled', false)
                ->where('payment_mode', PaymentMode::CASH->value)
                ->sum('amount');
            $handedOver = (float) CashHandover::where('festival_id', $entry->festival_id)
                ->where('from_user_id', $entry->collector_id)
                ->where('status', HandoverStatus::VERIFIED_ACCEPTED)
                ->sum('amount');

            if ($handedOver > $collected - (float) $entry->amount + 0.001) {
                throw new \InvalidArgumentException(
                    'This receipt has already been included in a verified handover and cannot be cancelled.'
                );
            }
        }

        return DB::transaction(function () use ($entry, $notes, $userId) {
            $entry->is_cancelled = true;
            $entry->cancelled_at = now();
            $entry->cancelled_by_user_id = $userId;
            if ($notes !== null) {
                $entry->notes = $notes;
            }
            $entry->save();

            // Reverse the bucket credit added at creation.
            $balance = FestivalBalance::forFestival($entry->festival_id);
            $bucket = match ($entry->payment_mode) {
                PaymentMode::CASH => 'cash_collectors',
                PaymentMode::UPI => 'upi',
                PaymentMode::CHEQUE, PaymentMode::NET_BANKING => 'bank',
            };
            $balance->addToBucket($bucket, -(float) $entry->amount);

            // Append-only reversal entry (the trail is never mutated or deleted).
            $reversalType = match ($entry->payment_mode) {
                PaymentMode::CASH => MoneyTrailType::CASH_RECEIVED,
                PaymentMode::UPI => MoneyTrailType::UPI_RECEIVED,
                PaymentMode::CHEQUE, PaymentMode::NET_BANKING => MoneyTrailType::BANK_DEPOSIT,
            };
            MoneyTrailEntry::create([
                'festival_id' => $entry->festival_id,
                'type' => $reversalType,
                'title' => 'Vargani Receipt #'.$entry->receipt_number.' cancelled',
                'subtitle' => $entry->donor_name,
                'amount' => $entry->amount,
                'is_positive' => false,
                'reference_id' => $entry->id,
                'reference_type' => VarganiEntry::class,
            ]);

            if ($entry->receipt_book_id) {
                ReceiptBook::where('id', $entry->receipt_book_id)->increment('cancelled_count');
            }

            return $entry;
        });
    }

    /**
     * Draw the next number from the book's own pre-printed range.
     * Cancelled numbers stay consumed (used_count is never decremented).
     * Called inside createVargani's transaction; the row lock serializes
     * concurrent writes to the same book.
     */
    protected function nextBookReceiptNumber(ReceiptBook $book): int
    {
        $book = ReceiptBook::where('id', $book->id)->lockForUpdate()->first();

        $number = $book->start_number + $book->used_count;

        if ($number > $book->end_number) {
            throw new \InvalidArgumentException('Receipt book is fully used. Create a new book.');
        }

        return $number;
    }

    protected function createMoneyTrailForVargani(VarganiEntry $entry): void
    {
        $type = match ($entry->payment_mode) {
            PaymentMode::CASH => MoneyTrailType::CASH_RECEIVED,
            PaymentMode::UPI => MoneyTrailType::UPI_RECEIVED,
            PaymentMode::CHEQUE, PaymentMode::NET_BANKING => MoneyTrailType::BANK_DEPOSIT,
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
