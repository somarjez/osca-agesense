<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class EncryptedOrPlainText implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Older records may still be stored as plain text.
            return $value;
        }
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }
}
