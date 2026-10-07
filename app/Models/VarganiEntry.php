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

    /**
     * Resolve a receipt from a publicly shared identifier: the entry id or the
     * offline client UUID. Both are random; sequential receipt numbers are
     * deliberately not accepted.
     */
    public static function findByPublicId(string $publicId, array $with = []): ?self
    {
        $publicId = trim($publicId);
        if ($publicId === '') {
            return null;
        }

        return static::with($with)
            ->where(function ($q) use ($publicId) {
                $q->where('id', $publicId);
                // client_uuid is client-supplied; only match values long
                // enough to be a real UUID so short guessable ones can't be used.
                if (strlen($publicId) >= 32) {
                    $q->orWhere('client_uuid', $publicId);
                }
            })
            ->first();
    }

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
