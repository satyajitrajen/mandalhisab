<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Enums\VarganiReceiptType;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VarganiEntry extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'mandal_id',
        'receipt_number',
        'donor_name',
        'mobile_number',
        'amount',
        'payment_mode',
        'area',
        'address',
        'collector_id',
        'receipt_type',
        'receipt_book_id',
        'notes',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by_user_id',
        'client_uuid',
        'signature_url',
    ];

    protected $casts = [
        'payment_mode' => PaymentMode::class,
        'receipt_type' => VarganiReceiptType::class,
        'is_cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'vrg_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function collector(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function receiptBook(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ReceiptBook::class);
    }
}
