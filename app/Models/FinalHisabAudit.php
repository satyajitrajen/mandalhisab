<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalHisabAudit extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'opening_balance',
        'vargani_total',
        'other_income_total',
        'total_income',
        'total_expenses',
        'closing_balance',
        'president_signed',
        'treasurer_signed',
        'president_signed_at',
        'treasurer_signed_at',
        'president_user_id',
        'treasurer_user_id',
        'treasurer_auth_method',
        'pdf_report_url',
        'is_locked',
    ];

    protected $casts = [
        'treasurer_auth_method' => AuthMethod::class,
        'president_signed' => 'boolean',
        'treasurer_signed' => 'boolean',
        'president_signed_at' => 'datetime',
        'treasurer_signed_at' => 'datetime',
        'is_locked' => 'boolean',
        'opening_balance' => 'decimal:2',
        'vargani_total' => 'decimal:2',
        'other_income_total' => 'decimal:2',
        'total_income' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'fha_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function presidentUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'president_user_id');
    }

    public function treasurerUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'treasurer_user_id');
    }
}
