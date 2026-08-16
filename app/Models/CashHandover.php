<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\HandoverStatus;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashHandover extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'from_user_id',
        'to_user_id',
        'amount',
        'linked_entry_ids',
        'linked_entries_count',
        'linked_date_range',
        'notes',
        'photo_url',
        'status',
        'auth_method',
        'verification_notes',
        'verified_at',
    ];

    protected $casts = [
        'status' => HandoverStatus::class,
        'auth_method' => AuthMethod::class,
        'linked_entry_ids' => 'array',
        'verified_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function getIdPrefix(): string
    {
        return 'hnd_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function fromUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
