<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMode;
use App\Enums\VarganiReceiptType;
use App\Models\Festival;
use App\Models\MandalMember;
use App\Models\ReceiptBook;
use App\Models\VarganiEntry;
use App\Services\CacheKeyService;
use App\Services\VarganiService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class VarganiController
{
    use ApiResponse;

    public function __construct(protected VarganiService $varganiService)
    {
    }

    /**
     * GET /api/v1/festivals/:festivalId/vargani
     */
    public function index(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'paymentMode' => ['nullable', 'in:CASH,UPI,CHEQUE,NET_BANKING'],
            'area' => ['nullable', 'string'],
            'collectorId' => ['nullable', 'string'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'filter' => ['nullable', 'in:ALL,TODAY,CASH,UPI,CANCELLED'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'search' => $validated['search'] ?? null,
            'paymentMode' => $validated['paymentMode'] ?? null,
            'area' => $validated['area'] ?? null,
            'collectorId' => $validated['collectorId'] ?? null,
            'startDate' => $validated['startDate'] ?? null,
            'endDate' => $validated['endDate'] ?? null,
            'filter' => $validated['filter'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::varganiList($festival, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_VARGANI_LIST, function () use ($festival, $validated) {
            $query = VarganiEntry::where('festival_id', $festival);

            if (! empty($validated['search'])) {
                $query->where('donor_name', 'like', '%' . $validated['search'] . '%');
            }

            if (! empty($validated['paymentMode'])) {
                $query->where('payment_mode', $validated['paymentMode']);
            }

            if (! empty($validated['area'])) {
                $query->where('area', $validated['area']);
            }

            if (! empty($validated['collectorId'])) {
                $query->where('collector_id', $validated['collectorId']);
            }

            if (! empty($validated['startDate']) && ! empty($validated['endDate'])) {
                $query->whereBetween('created_at', [$validated['startDate'], $validated['endDate']]);
            }

            if (! empty($validated['filter'])) {
                match ($validated['filter']) {
                    'TODAY' => $query->whereDate('created_at', today()),
                    'CASH' => $query->where('payment_mode', PaymentMode::CASH),
                    'UPI' => $query->where('payment_mode', PaymentMode::UPI),
                    'CANCELLED' => $query->where('is_cancelled', true),
                    default => null,
                };
            }

            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;
            $entries = $query->orderBy('receipt_number', 'desc')->paginate($limit, ['*'], 'page', $page);

            $data = $entries->map(function ($v) {
                return [
                    'id' => $v->id,
                    'receiptNumber' => $v->receipt_number,
                    'donorName' => $v->donor_name,
                    'mobileNumber' => $v->mobile_number,
                    'amount' => (float) $v->amount,
                    'paymentMode' => $v->payment_mode->value,
                    'area' => $v->area,
                    'address' => $v->address,
                    'collectorId' => $v->collector_id,
                    'collectorName' => $v->collector?->full_name,
                    'receiptType' => $v->receipt_type->value,
                    'isCancelled' => $v->is_cancelled,
                    'cancelledAt' => $v->cancelled_at?->toIso8601String(),
                    'createdAt' => $v->created_at?->toIso8601String(),
                ];
            });

            return $this->success($data, 'Vargani list retrieved', 200, [
                'meta' => [
                    'page' => $entries->currentPage(),
                    'limit' => $entries->perPage(),
                    'totalRecords' => $entries->total(),
                    'totalPages' => $entries->lastPage(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * POST /api/v1/festivals/:festivalId/vargani
     */
    public function store(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkCollectorOrAbove($festivalModel);

        $validated = $request->validate([
            'donorName' => ['required', 'string', 'min:2', 'max:60'],
            'mobileNumber' => ['nullable', 'string', 'size:10'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paymentMode' => ['required', 'string', 'in:CASH,UPI,CHEQUE,NET_BANKING'],
            'area' => ['required', 'string'],
            'address' => ['nullable', 'string'],
            'collectorId' => ['nullable', 'string'],
            'receiptType' => ['required', 'string', 'in:DIGITAL,PHYSICAL_BOOK'],
            'receiptBookId' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:250'],
            'clientUuid' => ['nullable', 'string'],
        ]);

        if ($validated['receiptType'] === 'PHYSICAL_BOOK' && empty($validated['receiptBookId'])) {
            return $this->error('VALIDATION_FAILED', 'receiptBookId is required for PHYSICAL_BOOK', 422);
        }

        if (! empty($validated['receiptBookId'])) {
            $book = ReceiptBook::where('festival_id', $festival)->find($validated['receiptBookId']);
            if (! $book) {
                return $this->error('NOT_FOUND', 'Receipt book not found in this festival', 404);
            }
            if ($book->status->value === 'CANCELLED' || $book->status->value === 'LOST') {
                return $this->error('VALIDATION_FAILED', 'Receipt book is ' . $book->status->value, 422);
            }
        }

        $collectorId = $validated['collectorId'] ?? auth()->id();

        try {
            $entry = $this->varganiService->createVargani($festival, [
                'donor_name' => $validated['donorName'],
                'mobile_number' => $validated['mobileNumber'] ?? null,
                'amount' => $validated['amount'],
                'payment_mode' => $validated['paymentMode'],
                'area' => $validated['area'],
                'address' => $validated['address'] ?? null,
                'receipt_type' => $validated['receiptType'],
                'receipt_book_id' => $validated['receiptBookId'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'client_uuid' => $validated['clientUuid'] ?? null,
            ], $collectorId);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        CacheKeyService::clearVargani($festival);

        return $this->success([
            'id' => $entry->id,
            'receiptNumber' => $entry->receipt_number,
            'donorName' => $entry->donor_name,
            'amount' => (float) $entry->amount,
            'paymentMode' => $entry->payment_mode->value,
            'area' => $entry->area,
            'receiptType' => $entry->receipt_type->value,
            'createdAt' => $entry->created_at?->toIso8601String(),
        ], 'Vargani created successfully', 201);
    }

    /**
     * GET /api/v1/festivals/:festivalId/vargani/:varganiId
     * or GET /api/v1/vargani/:varganiId
     */
    public function show(Request $request, ...$args)
    {
        $varganiId = count($args) === 2 ? $args[1] : $args[0];
        if ($varganiId instanceof VarganiEntry) {
            $varganiModel = $varganiId;
        } else {
            $varganiModel = VarganiEntry::find($varganiId);
        }

        if (! $varganiModel) {
            return $this->error('NOT_FOUND', 'Vargani entry not found', 404);
        }

        $this->checkMembership($varganiModel->festival);

        return $this->success([
            'id' => $varganiModel->id,
            'receiptNumber' => $varganiModel->receipt_number,
            'donorName' => $varganiModel->donor_name,
            'mobileNumber' => $varganiModel->mobile_number,
            'amount' => (float) $varganiModel->amount,
            'paymentMode' => $varganiModel->payment_mode->value,
            'area' => $varganiModel->area,
            'address' => $varganiModel->address,
            'collectorId' => $varganiModel->collector_id,
            'collectorName' => $varganiModel->collector?->full_name,
            'receiptType' => $varganiModel->receipt_type->value,
            'receiptBookId' => $varganiModel->receipt_book_id,
            'bookNumber' => $varganiModel->receiptBook?->book_number,
            'isCancelled' => $varganiModel->is_cancelled,
            'cancelledAt' => $varganiModel->cancelled_at?->toIso8601String(),
            'notes' => $varganiModel->notes,
            'createdAt' => $varganiModel->created_at?->toIso8601String(),
        ], 'Vargani details retrieved');
    }

    /**
     * POST /api/v1/festivals/:festivalId/vargani/:varganiId/cancel
     */
    public function cancel(Request $request, $festival, VarganiEntry $vargani)
    {
        if ($vargani->festival_id !== $festival) {
            return $this->error('NOT_FOUND', 'Vargani entry not found in this festival', 404);
        }

        $this->checkMembership($vargani->festival);

        if ($vargani->is_cancelled) {
            return $this->error('VALIDATION_FAILED', 'Receipt is already cancelled', 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $vargani->update([
            'is_cancelled' => true,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => auth()->id(),
            'notes' => $validated['notes'] ?? $vargani->notes,
        ]);

        CacheKeyService::clearVargani($festival);

        return $this->success([
            'id' => $vargani->id,
            'receiptNumber' => $vargani->receipt_number,
            'isCancelled' => true,
            'cancelledAt' => $vargani->cancelled_at?->toIso8601String(),
        ], 'Vargani cancelled successfully');
    }

    /**
     * POST /api/v1/festivals/:festivalId/vargani/:varganiId/signature
     */
    public function uploadSignature(Request $request, $festival, VarganiEntry $vargani)
    {
        if ($vargani->festival_id !== $festival) {
            return $this->error('NOT_FOUND', 'Vargani entry not found in this festival', 404);
        }

        $this->checkMembership($vargani->festival);

        if ($request->hasFile('signatureFile')) {
            $path = $request->file('signatureFile')->store('signatures/' . $festival, 'public');
            $url = asset('storage/' . $path);
        } else {
            $validated = $request->validate([
                'signatureBase64' => ['required', 'string'],
            ]);
            $path = 'signatures/' . uniqid() . '.png';
            $data = base64_decode(explode(',', $validated['signatureBase64'])[1] ?? $validated['signatureBase64']);
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $data);
            $url = asset('storage/' . $path);
        }

        CacheKeyService::clearVargani($festival);

        return $this->success(['signatureUrl' => $url], 'Signature uploaded');
    }

    /**
     * GET /api/v1/festivals/:festivalId/vargani/export
     */
    public function export(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $query = VarganiEntry::where('festival_id', $festival)->where('is_cancelled', false);

        if ($request->has('startDate') && $request->has('endDate')) {
            $query->whereBetween('created_at', [$request->input('startDate'), $request->input('endDate')]);
        }

        $entries = $query->orderBy('receipt_number', 'desc')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="vargani_export.csv"',
        ];

        $csv = "Receipt Number,Donor Name,Mobile,Amount,Payment Mode,Area,Date\n";
        foreach ($entries as $e) {
            $csv .= implode(',', [
                $e->receipt_number,
                \App\Helpers\CsvSanitizer::cell($e->donor_name),
                $e->mobile_number ?? '',
                $e->amount,
                $e->payment_mode->value,
                \App\Helpers\CsvSanitizer::cell($e->area),
                $e->created_at?->format('Y-m-d'),
            ]) . "\n";
        }

        return response($csv, 200, $headers);
    }

    /**
     * GET /api/v1/festivals/:festivalId/vargani/:varganiId/pdf
     */
    public function pdf(Request $request, $festival, VarganiEntry $vargani)
    {
        if ($vargani->festival_id !== $festival) {
            return $this->error('NOT_FOUND', 'Vargani entry not found in this festival', 404);
        }
$this->checkMembership($vargani->festival);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.vargani', ['vargani' => $vargani]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-' . $vargani->receipt_number . '.pdf"',
        ]);
    }

    /**
     * GET /api/v1/public/receipts/:receiptNumber
     */
    public function publicReceipt($receiptNumber)
    {
        $entry = VarganiEntry::where('receipt_number', $receiptNumber)
            ->where('is_cancelled', false)
            ->first();

        if (! $entry) {
            return $this->error('NOT_FOUND', 'Receipt not found', 404);
        }

        return $this->success([
            'receiptNumber' => $entry->receipt_number,
            'donorName' => $entry->donor_name,
            'amount' => (float) $entry->amount,
            'paymentMode' => $entry->payment_mode->value,
            'area' => $entry->area,
            'date' => $entry->created_at?->format('Y-m-d'),
            'festivalName' => $entry->festival->name . ' ' . $entry->festival->year,
            'mandalName' => $entry->festival->mandal->name ?? null,
        ], 'Receipt details');
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

    /**
     * Only collectors (or above) may record vargani collections.
     */
    protected function checkCollectorOrAbove(Festival $festival): void
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

        if (! in_array($membership->role, [
            \App\Enums\MemberRole::ADMIN,
            \App\Enums\MemberRole::SUPER_ADMIN,
            \App\Enums\MemberRole::TREASURER,
            \App\Enums\MemberRole::COLLECTOR,
        ], true)) {
            abort(response()->json([
                'success' => false,
                'statusCode' => 403,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Only collectors or admins can record vargani',
                ],
            ], 403));
        }
    }
}
