<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasPrefixedId
{
    protected static function bootHasPrefixedId(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $prefix = $model->getIdPrefix();
                $model->{$model->getKeyName()} = $prefix . Str::random(12);
            }
        });
    }

    abstract public function getIdPrefix(): string;
}
