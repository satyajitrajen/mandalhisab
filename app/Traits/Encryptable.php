<?php

namespace App\Traits;

use Illuminate\Support\Facades\Crypt;

trait Encryptable
{
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->encryptable ?? [], true)) {
            $value = Crypt::encryptString($value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->encryptable ?? [], true) && $value !== null) {
            $value = Crypt::decryptString($value);
        }

        return $value;
    }

    public function getArrayableAttributes()
    {
        $attributes = parent::getArrayableAttributes();

        foreach ($this->encryptable ?? [] as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== null) {
                $attributes[$key] = $this->getAttribute($key);
            }
        }

        return $attributes;
    }
}
