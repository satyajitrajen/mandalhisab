<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasPrefixedId, SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'full_name',
        'username',
        'phone',
        'email',
        'password',
        'security_pin',
        'avatar_url',
        'default_language',
        'is_biometric_enabled',
        'active_festival_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'security_pin',
    ];

    protected $casts = [
        'password' => 'hashed',
        'security_pin' => 'hashed',
        'is_biometric_enabled' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    public function getIdPrefix(): string
    {
        return 'usr_';
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function mandalMembers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MandalMember::class);
    }

    public function deviceTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function activeFestival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class, 'active_festival_id');
    }

    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', $this->full_name);
        $initials = '';
        foreach ($parts as $part) {
            if (! empty($part)) {
                $initials .= strtoupper($part[0]);
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        return $initials ?: 'U';
    }
}
