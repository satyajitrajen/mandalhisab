<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MandalArea extends Model
{
    protected $table = 'mandal_areas';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mandal_id',
        'name',
        'ward_number',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = 'area_' . Str::random(12);
            }
        });
    }

    public function mandal(): BelongsTo
    {
        return $this->belongsTo(Mandal::class, 'mandal_id', 'id');
    }
}
