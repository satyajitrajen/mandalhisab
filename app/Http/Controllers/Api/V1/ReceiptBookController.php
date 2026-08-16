<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReceiptBookStatus;
use App\Models\Festival;
use App\Models\MandalMember;
use App\Models\ReceiptBook;
use App\Services\CacheKeyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceiptBookController
{
    use ApiResponse;

    /**
     * GET /api/v1/festivals/:festivalId/receipt-books
     */
    public function index(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $validated = $request->validate([
            'status' => ['nullable', 'in:ACTIVE,COMPLETED,LOST,CANCELLED'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'status' => $validated['status'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::receiptBooksList($festival, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_RECEIPT_BOOKS, function () use ($festival, $validated) {
            $query = ReceiptBook::where('festival_id', $festival);

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;
            $books = $query->orderBy('start_number', 'asc')->paginate($limit, ['*'], 'page', $page);

            $data = $books->map(function ($b) {
                return [
                    'id' => $b->id,
                    'bookNumber' => $b->book_number,
                    'startNumber' => (int) $b->start_number,
                    'endNumber' => (int) $b->end_number,
                    'status' => $b->status->value,
                    'assignedTo' => $b->assignedTo?->full_name,
                    'assignedToId' => $b->assigned_to_user_id,
                    'assignedDate' => $b->assigned_date?->format('Y-m-d'),
                    'usedCount' => $b->used_count,
                    'cancelledCount' => $b->cancelled_count,
                ];
            });

            return $this->success($data, 'Receipt book list retrieved', 200, [
                'meta' => [
                    'page' => $books->currentPage(),
                    'limit' => $books->perPage(),
                    'totalRecords' => $books->total(),
                    'totalPages' => $books->lastPage(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * POST /api/v1/festivals/:festivalId/receipt-books
     */
    public function store(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $validated = $request->validate([
            'bookNumber' => ['required', 'string', 'max:255'],
            'startNumber' => ['required', 'integer', 'min:1'],
            'endNumber' => ['required', 'integer', 'gt:startNumber'],
            'assignedToId' => ['nullable', 'string'],
            'assignedDate' => ['nullable', 'date'],
        ]);

        // Check for overlapping ranges in this festival
        $overlap = ReceiptBook::where('festival_id', $festival)
            ->where(function ($q) use ($validated) {
                $q->where('start_number', '<=', $validated['endNumber'])
                  ->where('end_number', '>=', $validated['startNumber']);
            })
            ->exists();

        if ($overlap) {
            return $this->error('CONFLICT', 'Receipt book number range overlaps with an existing book in this festival', 409);
        }

        $book = ReceiptBook::create([
            'festival_id' => $festival,
            'book_number' => $validated['bookNumber'],
            'start_number' => $validated['startNumber'],
            'end_number' => $validated['endNumber'],
            'assigned_to_user_id' => $validated['assignedToId'] ?? null,
            'assigned_date' => $validated['assignedDate'] ?? null,
            'status' => ReceiptBookStatus::ACTIVE,
        ]);

        CacheKeyService::clearReceiptBooks($festival);

        return $this->success([
            'id' => $book->id,
            'bookNumber' => $book->book_number,
            'startNumber' => (int) $book->start_number,
            'endNumber' => (int) $book->end_number,
            'status' => $book->status->value,
            'assignedTo' => $book->assignedTo?->full_name,
            'assignedToId' => $book->assigned_to_user_id,
            'assignedDate' => $book->assigned_date?->format('Y-m-d'),
            'usedCount' => $book->used_count ?? 0,
            'cancelledCount' => $book->cancelled_count ?? 0,
        ], 'Receipt book created successfully', 201);
    }

    /**
     * GET /api/v1/receipt-books/:book
     */
    public function show(Request $request, ReceiptBook $book)
    {
        $this->checkMembership($book->festival);

        return $this->success([
            'id' => $book->id,
            'bookNumber' => $book->book_number,
            'startNumber' => (int) $book->start_number,
            'endNumber' => (int) $book->end_number,
            'status' => $book->status->value,
            'assignedTo' => $book->assignedTo?->full_name,
            'assignedToId' => $book->assigned_to_user_id,
            'assignedDate' => $book->assigned_date?->format('Y-m-d'),
            'usedCount' => $book->used_count,
            'cancelledCount' => $book->cancelled_count,
            'festivalName' => $book->festival->name,
        ], 'Receipt book details retrieved');
    }

    /**
     * POST /api/v1/receipt-books/:book/assign
     */
    public function assign(Request $request, ReceiptBook $book)
    {
        $this->checkMembership($book->festival);

        $collector = $request->input('collectorId') ?? $request->input('collectorName') ?? $request->input('assignedTo');
        if (empty($collector)) {
            return $this->error('VALIDATION_FAILED', 'The collectorId field is required.', 422);
        }

        $user = \App\Models\User::where('id', $collector)
            ->orWhere('full_name', $collector)
            ->orWhere('phone', $collector)
            ->first();

        if (! $user) {
            return $this->error('VALIDATION_FAILED', 'Selected collector was not found', 422);
        }

        $book->update([
            'assigned_to_user_id' => $user->id,
            'assigned_date' => now(),
        ]);

        CacheKeyService::clearReceiptBooks($book->festival_id);

        return $this->success([
            'id' => $book->id,
            'assignedTo' => $user->full_name,
            'assignedToId' => $user->id,
            'assignedDate' => $book->assigned_date?->format('Y-m-d'),
        ], 'Receipt book assigned successfully');
    }

    /**
     * PATCH /api/v1/receipt-books/:book/status
     */
    public function updateStatus(Request $request, ReceiptBook $book)
    {
        $this->checkMembership($book->festival);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ReceiptBookStatus::class)],
            'notes' => ['nullable', 'string'],
        ]);

        $book->update([
            'status' => $validated['status'],
        ]);

        CacheKeyService::clearReceiptBooks($book->festival_id);

        return $this->success([
            'id' => $book->id,
            'status' => $book->status->value,
        ], 'Receipt book status updated');
    }

    protected function checkMembership(Festival $festival): void
    {
        $membership = MandalMember::where('mandal_id', $festival->mandal_id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            abort(response()->json([
                'success' => false,
                'statusCode' => 403,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not a member of this mandal',
                ],
            ], 403));
        }
    }
}
