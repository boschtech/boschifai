<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Models\Run;
use App\Models\WorkspaceAiCredential;

/**
 * A workspace that saved its own (validated) Anthropic key runs on it — billed by Anthropic to
 * that workspace, no credits used. Every other workspace runs on the platform key and credits.
 */
class WorkspaceCredentialResolver implements ClaudeCredentialResolver
{
    public function __construct(private PlatformCredentialResolver $platform) {}

    public function resolveFor(Run $run, string $stepKey): ResolvedClaudeCredentials
    {
        $own = WorkspaceAiCredential::activeFor($run->workspace_id);
        if ($own?->provider !== ClaudeCredentialSource::Anthropic) {
            return $this->platform->resolveFor($run, $stepKey);
        }

        $apiKey = $own->secrets['api_key'];

        return new ResolvedClaudeCredentials(
            env: ['ANTHROPIC_API_KEY' => $apiKey],
            model: (string) config('boschifai.claude.model'),
            billingMode: ClaudeBillingMode::CustomerAccount,
            source: ClaudeCredentialSource::Anthropic,
            secretsToRedact: [$apiKey],
        );
    }
}
