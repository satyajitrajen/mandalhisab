<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MandalMember extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mandal_id',
        'user_id',
        'role',
        'area',
        'is_default',
        'is_active',
        'joined_at',
    ];

    protected $casts = [
        'role' => MemberRole::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'joined_at' => 'datetime',
    ];

    public function getIdPrefix(): string
    {
        return 'mm_';
    }

    public function mandal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Mandal::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
