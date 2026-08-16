<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'mandal_id',
        'festival_id',
        'title',
        'body',
        'type',
        'reference_id',
        'is_read',
    ];

    protected $casts = [
        'type' => NotificationType::class,
        'is_read' => 'boolean',
    ];

    public function getIdPrefix(): string
    {
        return 'notif_';
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mandal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Mandal::class);
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }
}
