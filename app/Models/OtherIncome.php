<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $festival_id
 * @property string $title
 * @property float|string $amount
 * @property \Illuminate\Support\Carbon|null $date
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OtherIncome extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'other_income';

    protected $fillable = [
        'id',
        'festival_id',
        'title',
        'amount',
        'date',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'inc_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
