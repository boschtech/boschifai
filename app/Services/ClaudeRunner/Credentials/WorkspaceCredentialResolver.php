<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Models\Run;
use App\Models\WorkspaceAiCredential;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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
            ClaudeCredentialSource::Vertex => $this->vertex($own),
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

    /**
     * The CLI's Google client only reads credentials from a file, so the key is written for this
     * step only: owner-readable, outside every repo checkout, and deleted when the step ends
     * (StepExecutionService runs the cleanup whatever happens). Provider, project, region and the
     * official endpoint are pinned over the repo's own settings. No ANTHROPIC_API_KEY is set.
     */
    private function vertex(WorkspaceAiCredential $own): ResolvedClaudeCredentials
    {
        $key = GoogleServiceAccountKey::fromArray($own->secrets['service_account']);
        $config = $own->config;

        $directory = storage_path('app/private/ai-credentials');
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/'.Str::random(40).'.json';
        touch($path);
        chmod($path, 0600);
        File::put($path, json_encode($key->fields, JSON_UNESCAPED_SLASHES));

        return new ResolvedClaudeCredentials(
            env: ['GOOGLE_APPLICATION_CREDENTIALS' => $path],
            model: $config['model'],
            billingMode: ClaudeBillingMode::CustomerAccount,
            source: ClaudeCredentialSource::Vertex,
            secretsToRedact: $key->secretParts(),
            cleanup: fn () => File::delete($path),
            pinnedEnv: [
                'CLAUDE_CODE_USE_VERTEX' => '1',
                'GOOGLE_APPLICATION_CREDENTIALS' => $path,
                'ANTHROPIC_VERTEX_PROJECT_ID' => $config['project_id'],
                'CLOUD_ML_REGION' => $config['region'],
                'ANTHROPIC_VERTEX_BASE_URL' => VertexEndpoint::baseUrl($config['region']),
                'ANTHROPIC_DEFAULT_HAIKU_MODEL' => $config['model'],
            ],
        );
    }
}
