<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\RegistrationPaymentStatus;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegistrationPayment extends Model
{
    use HasPrefixedId, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mandal_id',
        'user_id',
        'gateway',
        'razorpay_order_id',
        'razorpay_payment_id',
        'apple_transaction_id',
        'apple_original_transaction_id',
        'amount_paise',
        'currency',
        'status',
        'paid_at',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'gateway' => PaymentGateway::class,
        'status' => RegistrationPaymentStatus::class,
        'amount_paise' => 'integer',
        'paid_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    protected $attributes = [
        'gateway' => 'razorpay',
    ];

    public function getIdPrefix(): string
    {
        return 'pay_';
    }

    public function mandal(): BelongsTo
    {
        return $this->belongsTo(Mandal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
