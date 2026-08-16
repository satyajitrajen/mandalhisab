<?php

namespace App\Models;

use App\Enums\BankAccountType;
use App\Traits\Encryptable;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use HasFactory, HasPrefixedId, Encryptable;

    public $incrementing = false;
    protected $keyType = 'string';

    protected array $encryptable = ['account_number'];

    protected $fillable = [
        'id',
        'festival_id',
        'bank_name',
        'account_number',
        'ifsc',
        'account_type',
        'balance',
        'upi_id',
        'is_active',
    ];

    protected $casts = [
        'account_type' => BankAccountType::class,
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getIdPrefix(): string
    {
        return 'acc_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function fundTransfers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FundTransfer::class);
    }
}
