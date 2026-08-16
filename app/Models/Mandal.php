<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mandal extends Model
{
    use HasFactory, HasPrefixedId, SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'registration_number',
        'established_year',
        'address',
        'city',
        'pincode',
        'ward_number',
        'contact_number',
        'logo_url',
        'upi_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'established_year' => 'integer',
    ];

    public function getIdPrefix(): string
    {
        return 'mnd_';
    }

    public function mandalMembers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MandalMember::class);
    }

    public function festivals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Festival::class);
    }

    public function createdByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
