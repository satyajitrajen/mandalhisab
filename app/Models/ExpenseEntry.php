<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMode;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $festival_id
 * @property string $title
 * @property \App\Enums\ExpenseCategory $category
 * @property float|string $amount
 * @property \App\Enums\PaymentMode $payment_mode
 * @property string $paid_to
 * @property \Illuminate\Support\Carbon|null $date
 * @property \App\Enums\ExpenseStatus $status
 * @property string|null $bill_url
 * @property string|null $bill_pending_reason
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property string|null $client_uuid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ExpenseEntry extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'title',
        'category',
        'amount',
        'payment_mode',
        'paid_to',
        'date',
        'status',
        'bill_url',
        'bill_pending_reason',
        'notes',
        'created_by_user_id',
        'client_uuid',
    ];

    protected $casts = [
        'category' => ExpenseCategory::class,
        'payment_mode' => PaymentMode::class,
        'status' => ExpenseStatus::class,
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'exp_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->createdBy();
    }
}
