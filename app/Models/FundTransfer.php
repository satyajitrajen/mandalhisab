<?php

namespace App\Models;

use App\Enums\FundBucket;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundTransfer extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'from_bucket',
        'to_bucket',
        'bank_account_id',
        'amount',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'from_bucket' => FundBucket::class,
        'to_bucket' => FundBucket::class,
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'txn_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function bankAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
