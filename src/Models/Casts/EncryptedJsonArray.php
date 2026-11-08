<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts an array attribute at rest.
 *
 * Unlike Laravel's `encrypted:array` cast, the ciphertext is stored as a JSON string
 * literal, so the stored value stays valid JSON and works with JSON-typed columns
 * (MySQL, MariaDB, PostgreSQL). Plaintext JSON arrays written before encryption was
 * introduced remain readable and are encrypted the next time the model is saved.
 *
 * @implements CastsAttributes<array, mixed>
 */
class EncryptedJsonArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return static::decode($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return static::encode($value);
    }

    public static function encode(mixed $value): string
    {
        $payload = Crypt::encryptString(json_encode($value ?? [], JSON_THROW_ON_ERROR));

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string)$value, true);

        if (is_string($decoded)) {
            $decoded = json_decode(Crypt::decryptString($decoded), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && is_string(json_decode($value, true));
    }
}
