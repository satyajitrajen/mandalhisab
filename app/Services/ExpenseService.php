<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\MoneyTrailType;
use App\Enums\PaymentMode;
use App\Models\ExpenseEntry;
use App\Models\FestivalBalance;
use App\Models\MoneyTrailEntry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseService
{
    /**
     * Create an expense. Validates bill file or pending reason.
     */
    public function createExpense(string $festivalId, array $data, string $userId): ExpenseEntry
    {
        $billUrl = $data['bill_url'] ?? null;
        if (! $billUrl && ! empty($data['bill_file'])) {
            $billUrl = $this->storeBill($festivalId, $data['bill_file']);
        }

        if (empty($billUrl) && empty($data['bill_pending_reason'])) {
            throw new \InvalidArgumentException('Either bill file or bill pending reason is required.');
        }

        $status = ExpenseStatus::from($data['status'] ?? 'PAID');

        $expense = ExpenseEntry::create([
            'festival_id' => $festivalId,
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'payment_mode' => PaymentMode::from($data['payment_mode']),
            'paid_to' => $data['paid_to'] ?? null,
            'date' => $data['date'] ?? now()->toDateString(),
            'status' => $status,
            'bill_url' => $billUrl,
            'bill_pending_reason' => $data['bill_pending_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $userId,
            'client_uuid' => $data['client_uuid'] ?? null,
        ]);

        if ($status === ExpenseStatus::PAID) {
            $this->createMoneyTrailForExpense($expense);
            $this->updateBalanceForExpense($expense);
        }

        return $expense;
    }

    /**
     * Attach or replace a bill file for an expense.
     */
    public function attachBill(string $expenseId, $file): ExpenseEntry
    {
        $expense = ExpenseEntry::findOrFail($expenseId);
        $expense->bill_url = $this->storeBill($expense->festival_id, $file);
        $expense->save();

        return $expense;
    }

    /**
     * Mark an expense as paid and record it in the money trail + balance ledger.
     */
    public function markPaid(string $expenseId): ExpenseEntry
    {
        $expense = ExpenseEntry::findOrFail($expenseId);
        $expense->status = ExpenseStatus::PAID;
        $expense->save();

        $this->createMoneyTrailForExpense($expense);
        $this->updateBalanceForExpense($expense);

        return $expense;
    }

    protected function storeBill(string $festivalId, $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'pdf';
        $filename = Str::uuid().'.'.$ext;
        $path = "bills/{$festivalId}/{$filename}";

        Storage::disk('local')->put($path, file_get_contents($file));

        return $path;
    }

    protected function createMoneyTrailForExpense(ExpenseEntry $expense): void
    {
        $type = match ($expense->payment_mode) {
            PaymentMode::CASH => MoneyTrailType::CASH_EXPENSE,
            PaymentMode::UPI => MoneyTrailType::UPI_EXPENSE,
            default => MoneyTrailType::CASH_EXPENSE,
        };

        MoneyTrailEntry::create([
            'festival_id' => $expense->festival_id,
            'type' => $type,
            'title' => $expense->title,
            'subtitle' => $expense->paid_to,
            'amount' => $expense->amount,
            'is_positive' => false,
            'reference_id' => $expense->id,
            'reference_type' => ExpenseEntry::class,
        ]);
    }

    /**
     * Deduct the expense from the appropriate festival balance bucket.
     */
    protected function updateBalanceForExpense(ExpenseEntry $expense): void
    {
        $balance = FestivalBalance::forFestival($expense->festival_id);

        $bucket = match ($expense->payment_mode) {
            PaymentMode::CASH => 'cash_treasurer',
            PaymentMode::UPI => 'upi',
            PaymentMode::CHEQUE, PaymentMode::NET_BANKING => 'bank',
        };

        $balance->addToBucket($bucket, -(float) $expense->amount);
    }
}
