<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\MandalMember;
use App\Services\CacheKeyService;
use App\Services\ExpenseService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpenseController
{
    use ApiResponse;

    public function __construct(protected ExpenseService $expenseService)
    {
    }

    /**
     * GET /api/v1/festivals/:festivalId/expenses
     */
    public function index(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $validated = $request->validate([
            'category' => ['nullable', 'string', 'in:STAGE_MANDAP,SOUND_LIGHTING,MURTI_DECORATION,POOJA_PRASAD,SECURITY_LOGISTICS,MISCELLANEOUS'],
            'status' => ['nullable', 'in:PAID,PENDING'],
            'hasBill' => ['nullable', 'in:true,false'],
            'filter' => ['nullable', 'in:ALL,TODAY,PENDING,PENDING_BILL'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paramsHash = CacheKeyService::paramsHash(array_filter([
            'category' => $validated['category'] ?? null,
            'status' => $validated['status'] ?? null,
            'hasBill' => $validated['hasBill'] ?? null,
            'filter' => $validated['filter'] ?? null,
            'page' => $validated['page'] ?? 1,
            'limit' => $validated['limit'] ?? 20,
        ]));
        $cacheKey = CacheKeyService::expensesList($festival, $paramsHash);

        return CacheKeyService::remember($cacheKey, CacheKeyService::TTL_EXPENSES_LIST, function () use ($festival, $validated) {
            $query = ExpenseEntry::where('festival_id', $festival);

            if (! empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (! empty($validated['hasBill'])) {
                if ($validated['hasBill'] === 'true') {
                    $query->whereNotNull('bill_url');
                } else {
                    $query->whereNull('bill_url');
                }
            }

            if (! empty($validated['filter'])) {
                match ($validated['filter']) {
                    'TODAY' => $query->whereDate('date', today()),
                    'PENDING' => $query->where('status', 'PENDING'),
                    'PENDING_BILL' => $query->whereNull('bill_url')->whereNotNull('bill_pending_reason'),
                    default => null,
                };
            }

            $page = $validated['page'] ?? 1;
            $limit = $validated['limit'] ?? 20;
            $expenses = $query->orderBy('date', 'desc')->paginate($limit, ['*'], 'page', $page);

            $data = $expenses->map(function ($e) {
                $categoryStr = $e->category instanceof \BackedEnum ? $e->category->value : (string) $e->category;
                $paymentModeStr = $e->payment_mode instanceof \BackedEnum ? $e->payment_mode->value : (string) $e->payment_mode;
                $statusStr = $e->status instanceof \BackedEnum ? $e->status->value : (string) $e->status;
                $dateStr = $e->date instanceof \DateTimeInterface ? $e->date->format('Y-m-d') : (string) $e->date;
                return [
                    'id' => $e->id,
                    'title' => $e->title,
                    'category' => $categoryStr,
                    'amount' => (float) $e->amount,
                    'paymentMode' => $paymentModeStr,
                    'paidTo' => $e->paid_to,
                    'date' => $dateStr,
                    'status' => $statusStr,
                    'billUrl' => $e->bill_url ? asset('storage/' . $e->bill_url) : null,
                    'billPendingReason' => $e->bill_pending_reason,
                    'notes' => $e->notes,
                    'createdAt' => $e->created_at?->toIso8601String(),
                ];
            });

            return $this->success($data, 'Expense list retrieved', 200, [
                'meta' => [
                    'page' => $expenses->currentPage(),
                    'limit' => $expenses->perPage(),
                    'totalRecords' => $expenses->total(),
                    'totalPages' => $expenses->lastPage(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * POST /api/v1/festivals/:festivalId/expenses
     */
    public function store(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:STAGE_MANDAP,SOUND_LIGHTING,MURTI_DECORATION,POOJA_PRASAD,SECURITY_LOGISTICS,MISCELLANEOUS'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paymentMode' => ['required', 'string', 'in:CASH,UPI,CHEQUE,NET_BANKING'],
            'paidTo' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:PAID,PENDING'],
            'billUrl' => ['nullable', 'string'],
            'billPendingReason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:250'],
            'clientUuid' => ['nullable', 'string'],
        ]);

        $billUrl = $validated['billUrl'] ?? null;
        if ($request->hasFile('billFile')) {
            $file = $request->file('billFile');
            $billUrl = $file->store('bills/' . $festival, 'public');
        }

        try {
            $expense = $this->expenseService->createExpense($festival, [
                'title' => $validated['title'],
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'payment_mode' => $validated['paymentMode'],
                'paid_to' => $validated['paidTo'],
                'date' => $validated['date'],
                'status' => $validated['status'],
                'bill_url' => $billUrl,
                'bill_pending_reason' => $validated['billPendingReason'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'client_uuid' => $validated['clientUuid'] ?? null,
            ], auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        CacheKeyService::clearExpenses($festival);

        return $this->success([
            'id' => $expense->id,
            'title' => $expense->title,
            'category' => $expense->category->value,
            'amount' => (float) $expense->amount,
            'paymentMode' => $expense->payment_mode->value,
            'paidTo' => $expense->paid_to,
            'date' => $expense->date?->format('Y-m-d'),
            'status' => $expense->status,
            'billUrl' => $expense->bill_url ? asset('storage/' . $expense->bill_url) : null,
            'notes' => $expense->notes,
            'createdAt' => $expense->created_at?->toIso8601String(),
        ], 'Expense created successfully', 201);
    }

    /**
     * GET /api/v1/expenses/:expenseId (shallow)
     */
    /**
     * GET /api/v1/festivals/:festivalId/expenses/:expenseId
     * or GET /api/v1/expenses/:expenseId
     */
    public function show(Request $request, ...$args)
    {
        $expenseId = count($args) === 2 ? $args[1] : $args[0];
        $expenseModel = $expenseId instanceof ExpenseEntry ? $expenseId : ExpenseEntry::find($expenseId);

        if (! $expenseModel) {
            return $this->error('NOT_FOUND', 'Expense not found', 404);
        }

        $this->checkMembership($expenseModel->festival);

        $categoryStr = $expenseModel->category instanceof \BackedEnum ? $expenseModel->category->value : (string) $expenseModel->category;
        $paymentModeStr = $expenseModel->payment_mode instanceof \BackedEnum ? $expenseModel->payment_mode->value : (string) $expenseModel->payment_mode;
        $statusStr = $expenseModel->status instanceof \BackedEnum ? $expenseModel->status->value : (string) $expenseModel->status;

        $dateStr = $expenseModel->date instanceof \DateTimeInterface ? $expenseModel->date->format('Y-m-d') : (string) $expenseModel->date;

        return $this->success([
            'id' => $expenseModel->id,
            'title' => $expenseModel->title,
            'category' => $categoryStr,
            'amount' => (float) $expenseModel->amount,
            'paymentMode' => $paymentModeStr,
            'paidTo' => $expenseModel->paid_to,
            'date' => $dateStr,
            'status' => $statusStr,
            'billUrl' => $expenseModel->bill_url ? asset('storage/' . $expenseModel->bill_url) : null,
            'billPendingReason' => $expenseModel->bill_pending_reason,
            'notes' => $expenseModel->notes,
            'createdBy' => $expenseModel->createdBy?->full_name ?? $expenseModel->creator?->full_name,
            'createdAt' => $expenseModel->created_at?->toIso8601String(),
        ], 'Expense details retrieved');
    }

    /**
     * PUT /api/v1/festivals/:festivalId/expenses/:expenseId
     * or PATCH /api/v1/expenses/:expenseId
     */
    public function update(Request $request, ...$args)
    {
        $expenseId = count($args) === 2 ? $args[1] : $args[0];
        $expenseModel = $expenseId instanceof ExpenseEntry ? $expenseId : ExpenseEntry::find($expenseId);

        if (! $expenseModel) {
            return $this->error('NOT_FOUND', 'Expense not found', 404);
        }

        $this->checkMembership($expenseModel->festival);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'in:STAGE_MANDAP,SOUND_LIGHTING,MURTI_DECORATION,POOJA_PRASAD,SECURITY_LOGISTICS,MISCELLANEOUS'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'paidTo' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:250'],
        ]);

        $updateData = array_filter([
            'title' => $validated['title'] ?? null,
            'category' => $validated['category'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'paid_to' => $validated['paidTo'] ?? null,
            'date' => $validated['date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], fn ($v) => $v !== null);

        $expenseModel->update($updateData);

        CacheKeyService::clearExpenses($expenseModel->festival_id);

        $catStr = $expenseModel->category instanceof \BackedEnum ? $expenseModel->category->value : (string) $expenseModel->category;
        $stStr = $expenseModel->status instanceof \BackedEnum ? $expenseModel->status->value : (string) $expenseModel->status;

        return $this->success([
            'id' => $expenseModel->id,
            'title' => $expenseModel->title,
            'category' => $catStr,
            'amount' => (float) $expenseModel->amount,
            'status' => $stStr,
        ], 'Expense updated successfully');
    }

    /**
     * POST /api/v1/festivals/:festivalId/expenses/:expenseId/bill
     */
    public function uploadBill(Request $request, $festival, $expense)
    {
        $expenseModel = ExpenseEntry::find($expense);
        if (! $expenseModel || $expenseModel->festival_id !== $festival) {
            return $this->error('NOT_FOUND', 'Expense not found in this festival', 404);
        }

        $this->checkMembership($expenseModel->festival);

        $request->validate([
            'billFile' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $file = $request->file('billFile');
        $path = $file->store('bills/' . $festival, 'public');

        $expenseModel->update([
            'bill_url' => $path,
            'bill_pending_reason' => null,
        ]);

        CacheKeyService::clearExpenses($festival);

        return $this->success([
            'id' => $expenseModel->id,
            'billUrl' => asset('storage/' . $path),
        ], 'Bill uploaded successfully');
    }

    /**
     * PATCH /api/v1/festivals/:festivalId/expenses/:expenseId/mark-paid
     */
    public function markPaid(Request $request, $festival, $expense)
    {
        $expenseModel = ExpenseEntry::find($expense);
        if (! $expenseModel || $expenseModel->festival_id !== $festival) {
            return $this->error('NOT_FOUND', 'Expense not found in this festival', 404);
        }

        $this->checkMembership($expenseModel->festival);

        if ($expenseModel->status->value === 'PAID') {
            return $this->error('VALIDATION_FAILED', 'Expense is already marked as paid', 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $expense = $this->expenseService->markPaid($expense);
        } catch (\Exception $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        CacheKeyService::clearExpenses($festival);

        return $this->success([
            'id' => $expense->id,
            'status' => 'PAID',
        ], 'Expense marked as paid');
    }

    /**
     * GET /api/v1/festivals/:festivalId/expenses/export
     */
    public function export(Request $request, $festival)
    {
        $festivalModel = Festival::findOrFail($festival);
        $this->checkMembership($festivalModel);

        $query = ExpenseEntry::where('festival_id', $festival);

        if ($request->has('startDate') && $request->has('endDate')) {
            $query->whereBetween('date', [$request->input('startDate'), $request->input('endDate')]);
        }

        $expenses = $query->orderBy('date', 'desc')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="expenses_export.csv"',
        ];

        $csv = "Title,Category,Amount,Payment Mode,Paid To,Date,Status\n";
        foreach ($expenses as $e) {
            $csv .= implode(',', [
                \App\Helpers\CsvSanitizer::cell($e->title),
                $e->category->value,
                $e->amount,
                $e->payment_mode->value,
                \App\Helpers\CsvSanitizer::cell($e->paid_to),
                $e->date?->format('Y-m-d'),
                $e->status->value,
            ]) . "\n";
        }

        return response($csv, 200, $headers);
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
