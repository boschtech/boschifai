<?php

namespace App\Casts;

use App\Support\Security\CredentialsEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** An array attribute encrypted with the dedicated credentials key (see CredentialsEncrypter). */
class EncryptedCredentials implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : app(CredentialsEncrypter::class)->decrypt($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(CredentialsEncrypter::class)->encrypt($value);
    }
}
