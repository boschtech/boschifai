<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Models\Run;

/**
 * Every workspace runs on BoschifAI's own Anthropic key and configured model — the behaviour
 * before this seam existed.
 *
 * An empty key is passed through as "not set" rather than rejected: the CLI can also sign in
 * with a Claude account stored under the worker's HOME, which some local setups rely on.
 */
class PlatformCredentialResolver implements ClaudeCredentialResolver
{
    public function resolveFor(Run $run, string $stepKey): ResolvedClaudeCredentials
    {
        $apiKey = config('boschifai.claude.api_key');

        return new ResolvedClaudeCredentials(
            env: ['ANTHROPIC_API_KEY' => $apiKey],
            model: (string) config('boschifai.claude.model'),
            billingMode: ClaudeBillingMode::Platform,
            source: ClaudeCredentialSource::Platform,
            secretsToRedact: array_filter([$apiKey]),
        );
    }
}
