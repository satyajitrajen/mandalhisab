<?php

namespace App\Models;

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
        'razorpay_order_id',
        'razorpay_payment_id',
        'amount_paise',
        'currency',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'status' => RegistrationPaymentStatus::class,
        'amount_paise' => 'integer',
        'paid_at' => 'datetime',
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
