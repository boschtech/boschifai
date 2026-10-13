<?php

namespace App\Support\Security;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts what workspaces store about their own AI provider, with its own key
 * (BOSCHIFAI_CREDENTIALS_KEY, AES-256-GCM) — separate from APP_KEY so either can be rotated on
 * its own. Previous keys (BOSCHIFAI_CREDENTIALS_PREVIOUS_KEYS) still decrypt, so values stay
 * readable during a rotation until boschifai:rotate-credentials-key re-encrypts them.
 * There is no fallback to APP_KEY: without the key nothing can be stored or read.
 */
class CredentialsEncrypter
{
    public const CIPHER = 'aes-256-gcm';

    private ?Encrypter $encrypter = null;

    public function isConfigured(): bool
    {
        return (string) config('boschifai.claude.credentials_encryption.key') !== '';
    }

    public function encrypt(array $value): string
    {
        return $this->encrypter()->encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $payload): array
    {
        return json_decode($this->encrypter()->decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
    }

    /** A fresh key in the format BOSCHIFAI_CREDENTIALS_KEY expects. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey(self::CIPHER));
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter) {
            return $this->encrypter;
        }
        if (! $this->isConfigured()) {
            throw new RuntimeException('BOSCHIFAI_CREDENTIALS_KEY is not set, so stored AI provider credentials can\'t be encrypted or read. Generate one with `php artisan boschifai:generate-credentials-key`.');
        }

        $config = config('boschifai.claude.credentials_encryption');

        return $this->encrypter = (new Encrypter(self::parseKey($config['key']), self::CIPHER))
            ->previousKeys(array_map(fn ($key) => self::parseKey($key), $config['previous_keys'] ?? []));
    }

    private static function parseKey(string $key): string
    {
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
    }
}
