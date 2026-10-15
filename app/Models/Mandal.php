<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'registration_paid_at' => 'datetime',
        'registration_paid_through' => 'date',
    ];

    /**
     * Registration is active through the end of the paid-through date.
     */
    public function isRegistrationActive(): bool
    {
        return $this->registration_paid_through !== null
            && $this->registration_paid_through->gte(today());
    }

    /**
     * Expired, never paid, or inside the final renewal window.
     */
    public function needsRegistrationPayment(): bool
    {
        if (! $this->isRegistrationActive()) {
            return true;
        }

        $window = (int) config('services.razorpay.renewal_window_days', 30);

        return $this->registration_paid_through->lt(today()->addDays($window));
    }

    public function getIdPrefix(): string
    {
        return 'mnd_';
    }

    public function mandalMembers(): HasMany
    {
        return $this->hasMany(MandalMember::class);
    }

    public function festivals(): HasMany
    {
        return $this->hasMany(Festival::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
