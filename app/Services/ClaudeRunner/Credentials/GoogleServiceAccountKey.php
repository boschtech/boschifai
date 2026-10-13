<?php

namespace App\Services\ClaudeRunner\Credentials;

use InvalidArgumentException;

/**
 * A customer's Google service-account key (the JSON file from Google Cloud), checked and reduced
 * to the standard fields. The token endpoint and universe are pinned to Google's own: the key file
 * is handed to the CLI's Google client, and a key naming another token endpoint would send a
 * signed assertion there.
 */
final class GoogleServiceAccountKey
{
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const FIELDS = [
        'type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id',
        'auth_uri', 'token_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain',
    ];

    /** @param  array<string, string>  $fields */
    private function __construct(public readonly array $fields) {}

    /** @throws InvalidArgumentException with a message for people */
    public static function fromJson(string $json): self
    {
        $key = json_decode($json, true);
        if (! is_array($key)) {
            throw new InvalidArgumentException("That isn't a JSON key file. Paste the whole file Google Cloud downloaded.");
        }
        if (($key['type'] ?? null) !== 'service_account') {
            throw new InvalidArgumentException('That is not a service-account key (its "type" must be "service_account").');
        }
        foreach (['private_key', 'client_email', 'project_id'] as $required) {
            if (! is_string($key[$required] ?? null) || trim($key[$required]) === '') {
                throw new InvalidArgumentException("The key file is missing \"{$required}\".");
            }
        }
        if (! openssl_pkey_get_private($key['private_key'])) {
            throw new InvalidArgumentException("The key file's private key can't be read.");
        }
        if (($key['token_uri'] ?? self::TOKEN_URI) !== self::TOKEN_URI || ($key['universe_domain'] ?? 'googleapis.com') !== 'googleapis.com') {
            throw new InvalidArgumentException('Only keys for Google Cloud itself (oauth2.googleapis.com) can be used.');
        }

        $fields = array_intersect_key($key, array_flip(self::FIELDS));
        $fields['token_uri'] = self::TOKEN_URI;

        return new self(array_map('strval', $fields));
    }

    public static function fromArray(array $fields): self
    {
        return new self($fields);
    }

    public function clientEmail(): string
    {
        return $this->fields['client_email'];
    }

    public function projectId(): string
    {
        return $this->fields['project_id'];
    }

    /**
     * What to strip from the agent's output: the private key line by line (the transcript is JSON
     * lines, where the key's newlines are escaped, so it can only be matched in pieces) and its id.
     *
     * @return list<string>
     */
    public function secretParts(): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $this->fields['private_key'])), fn ($line) => $line !== '' && ! str_starts_with($line, '-----'));

        return array_values(array_filter([...$lines, $this->fields['private_key_id'] ?? null]));
    }

    /** The signed JWT Google exchanges for a short-lived access token (valid for 10 minutes). */
    public function signedAssertion(int $now): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->fields['private_key_id'] ?? null])
            .'.'.$encode([
                'iss' => $this->clientEmail(),
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 600,
            ]);
        openssl_sign($unsigned, $signature, $this->fields['private_key'], OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
