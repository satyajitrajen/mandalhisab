<?php

namespace App\Services;

use App\Enums\ReceiptBookStatus;
use App\Models\ReceiptBook;
use Illuminate\Support\Facades\DB;

class ReceiptBookService
{
    /**
     * Create receipt books for a festival, validating no overlapping ranges.
     */
    public function createBooks(string $festivalId, array $booksArray): array
    {
        return DB::transaction(function () use ($festivalId, $booksArray) {
            $existing = ReceiptBook::where('festival_id', $festivalId)->get();
            $created = [];

            foreach ($booksArray as $bookData) {
                $start = (int) $bookData['start_number'];
                $end = (int) $bookData['end_number'];

                foreach ($existing as $eb) {
                    if ($start <= $eb->end_number && $end >= $eb->start_number) {
                        throw new \InvalidArgumentException(
                            "Range {$start}-{$end} overlaps with existing book {$eb->book_number}."
                        );
                    }
                }

                $book = ReceiptBook::create([
                    'festival_id' => $festivalId,
                    'book_number' => $bookData['book_number'],
                    'start_number' => $start,
                    'end_number' => $end,
                    'status' => ReceiptBookStatus::ACTIVE,
                ]);

                $created[] = $book;
                $existing->push($book);
            }

            return $created;
        });
    }

    /**
     * Assign a receipt book to a collector.
     */
    public function assignBook(string $bookId, string $collectorId): ReceiptBook
    {
        $book = ReceiptBook::findOrFail($bookId);
        $book->assigned_to_user_id = $collectorId;
        $book->assigned_date = now();
        $book->save();

        return $book;
    }

    /**
     * Update the status of a receipt book.
     */
    public function updateStatus(string $bookId, ReceiptBookStatus $status, ?string $notes = null): ReceiptBook
    {
        $book = ReceiptBook::findOrFail($bookId);
        $book->status = $status;
        $book->save();

        return $book;
    }
}
