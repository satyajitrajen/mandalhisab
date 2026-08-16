<?php

namespace App\Models;

use App\Enums\ReceiptBookStatus;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceiptBook extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'book_number',
        'start_number',
        'end_number',
        'assigned_to_user_id',
        'assigned_date',
        'status',
        'used_count',
        'cancelled_count',
    ];

    protected $casts = [
        'status' => ReceiptBookStatus::class,
        'assigned_date' => 'datetime',
        'start_number' => 'integer',
        'end_number' => 'integer',
        'used_count' => 'integer',
        'cancelled_count' => 'integer',
    ];

    public function getIdPrefix(): string
    {
        return 'bk_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function assignedTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function varganiEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VarganiEntry::class);
    }
}
