<?php

namespace App\Services\ClaudeRunner\Credentials;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks an Anthropic API key with one call to the models endpoint — no tokens are used. It
 * confirms the key is accepted; it can't tell whether the account has credit left (a run fails
 * with a clear message if it doesn't). Goes to the same pinned endpoint the CLI uses
 * (boschifai.claude.hardening.api_base_url). The key is sent only in the request header and
 * never logged or put into an error message.
 */
class AnthropicKeyValidator
{
    public const VALID = 'valid';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /** @return array{0: string, 1: ?string} [outcome, message for people] */
    public function check(string $apiKey): array
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim((string) config('boschifai.claude.hardening.api_base_url'), '/').'/v1/models', ['limit' => 1]);
        } catch (ConnectionException) {
            return [self::UNREACHABLE, "Couldn't reach Anthropic to check the key. Nothing was changed — try again in a moment."];
        }

        return match (true) {
            $response->successful() => [self::VALID, null],
            in_array($response->status(), [401, 403], true) => [self::REJECTED, 'Anthropic rejected this API key. Check that it was copied in full and is still active in the Anthropic Console.'],
            default => [self::UNREACHABLE, "Anthropic couldn't check the key right now (HTTP {$response->status()}). Nothing was changed — try again in a moment."],
        };
    }
}
