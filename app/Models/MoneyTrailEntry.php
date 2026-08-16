<?php

namespace App\Models;

use App\Enums\MoneyTrailType;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoneyTrailEntry extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'type',
        'title',
        'subtitle',
        'amount',
        'is_positive',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'type' => MoneyTrailType::class,
        'is_positive' => 'boolean',
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'mt_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }
}
