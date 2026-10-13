<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Models\Run;
use App\Models\WorkspaceAiCredential;

/**
 * A workspace that set up its own provider runs on it — billed by that provider to the
 * workspace, no credits used: its own Anthropic key, or its own Amazon Bedrock through a role in
 * its AWS account. Every other workspace runs on the platform key and credits.
 */
class WorkspaceCredentialResolver implements ClaudeCredentialResolver
{
    public function __construct(
        private PlatformCredentialResolver $platform,
        private BedrockRoleAssumer $roles,
    ) {}

    public function resolveFor(Run $run, string $stepKey): ResolvedClaudeCredentials
    {
        $own = WorkspaceAiCredential::activeFor($run->workspace_id);

        return match ($own?->provider) {
            ClaudeCredentialSource::Anthropic => $this->anthropic($own),
            ClaudeCredentialSource::Bedrock => $this->bedrock($own, $run),
            default => $this->platform->resolveFor($run, $stepKey),
        };
    }

    private function anthropic(WorkspaceAiCredential $own): ResolvedClaudeCredentials
    {
        $apiKey = $own->secrets['api_key'];

        return new ResolvedClaudeCredentials(
            env: ['ANTHROPIC_API_KEY' => $apiKey],
            model: (string) config('boschifai.claude.model'),
            billingMode: ClaudeBillingMode::CustomerAccount,
            source: ClaudeCredentialSource::Anthropic,
            secretsToRedact: [$apiKey],
        );
    }

    /**
     * Short-lived credentials for the workspace's role (STS, at most an hour — longer than any
     * step), with the provider, region and official endpoint pinned so a repo's own settings
     * can't redirect the agent's signed AWS requests. No ANTHROPIC_API_KEY is set at all.
     *
     * @throws BedrockAccessDenied
     */
    private function bedrock(WorkspaceAiCredential $own, Run $run): ResolvedClaudeCredentials
    {
        $config = $own->config;
        $aws = $this->roles->assume($config['role_arn'], $run->workspace->aiExternalId(), $config['region'], "boschifai-{$run->workspace_id}-run-{$run->id}", 3600);
        $endpoint = "https://bedrock-runtime.{$config['region']}.amazonaws.com";

        return new ResolvedClaudeCredentials(
            env: [
                'AWS_REGION' => $config['region'],
                'AWS_ACCESS_KEY_ID' => $aws['access_key_id'],
                'AWS_SECRET_ACCESS_KEY' => $aws['secret_access_key'],
                'AWS_SESSION_TOKEN' => $aws['session_token'],
            ],
            model: $config['model'],
            billingMode: ClaudeBillingMode::CustomerAccount,
            source: ClaudeCredentialSource::Bedrock,
            secretsToRedact: array_values($aws),
            pinnedEnv: [
                'CLAUDE_CODE_USE_BEDROCK' => '1',
                'AWS_REGION' => $config['region'],
                'ANTHROPIC_BEDROCK_BASE_URL' => $endpoint,
                'AWS_ENDPOINT_URL' => $endpoint,
                'AWS_ENDPOINT_URL_BEDROCK_RUNTIME' => $endpoint,
                // Background tasks would otherwise use a default Sonnet model the account may
                // not have enabled — use the workspace's chosen model for those too.
                'ANTHROPIC_DEFAULT_HAIKU_MODEL' => $config['model'],
            ],
        );
    }
}
