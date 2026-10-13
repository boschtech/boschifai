<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClaudeCredentialSource;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Workspace;
use App\Models\WorkspaceAiCredential;
use App\Services\ClaudeRunner\Credentials\AnthropicKeyValidator;
use App\Services\ClaudeRunner\Credentials\BedrockAccessValidator;
use App\Services\ClaudeRunner\Credentials\GoogleServiceAccountKey;
use App\Services\ClaudeRunner\Credentials\VertexAccessValidator;
use App\Services\ClaudeRunner\Credentials\VertexEndpoint;
use App\Support\CurrentWorkspace;
use App\Support\Security\CredentialsEncrypter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Settings → AI provider (owners and admins): run on the platform's key and credits, on the
 * workspace's own Anthropic API key, on its own Amazon Bedrock (a role in its AWS account that
 * BoschifAI assumes per step — no AWS keys are stored), or on its own Google Vertex AI (a
 * service-account key, stored encrypted). Settings are only saved once the provider
 * accepts them, and an API key is never sent back — responses show a hint ("…AbCd") only.
 */
class WorkspaceAiCredentialController extends Controller
{
    /** Same pattern AWS uses for regions in the commercial and GovCloud partitions. */
    private const AWS_REGION = '/^[a-z]{2}(-gov)?-[a-z]+-\d$/';

    private const ROLE_ARN = '/^arn:aws(-us-gov)?:iam::\d{12}:role\/[\w+=,.@\/-]{1,512}$/';

    /** e.g. claude-sonnet-4-5@20250929, claude-opus-4-6[1m]. */
    private const VERTEX_MODEL = '/^claude-[a-z0-9.-]+(@\d{8})?(\[1m\])?$/';

    /** An inference profile ID (us.anthropic.claude-…) or ARN. */
    private const BEDROCK_MODEL = '/^[\w.:\/-]{3,300}$/';

    public function show(CurrentWorkspace $current)
    {
        return $this->respond($current->get(), WorkspaceAiCredential::activeFor($current->id()));
    }

    public function update(Request $request, CurrentWorkspace $current, CredentialsEncrypter $encrypter)
    {
        $provider = $request->validate([
            'provider' => ['sometimes', Rule::in(['anthropic', 'bedrock', 'vertex'])],
        ])['provider'] ?? 'anthropic';

        // Checked before anything is sent anywhere: without the dedicated encryption key nothing
        // could be stored safely, and there's no fallback to APP_KEY.
        if (! $encrypter->isConfigured()) {
            report(new RuntimeException('A workspace tried to save its own AI key, but BOSCHIFAI_CREDENTIALS_KEY is not set.'));
            abort(503, "Using your own API key isn't available on this server yet. Contact BoschifAI support.");
        }

        $credential = match ($provider) {
            'bedrock' => $this->saveBedrock($request, $current->get()),
            'vertex' => $this->saveVertex($request, $current->get()),
            default => $this->saveAnthropic($request, $current->get()),
        };

        AuditEvent::record('ai_credentials.saved', $current->get(), $request->user(), $credential, ['provider' => $provider, 'hint' => $credential->secret_hint]);

        return $this->respond($current->get(), $credential);
    }

    /** Re-checks the stored settings, e.g. after a step failed with "key rejected". */
    public function check(CurrentWorkspace $current)
    {
        $credential = WorkspaceAiCredential::activeFor($current->id());
        abort_unless($credential, 404, 'This workspace uses the platform key — there is no key of its own to check.');

        [$outcome, $message] = match ($credential->provider) {
            ClaudeCredentialSource::Bedrock => $this->checkBedrock($current->get(), $credential->config),
            ClaudeCredentialSource::Vertex => app(VertexAccessValidator::class)->check(GoogleServiceAccountKey::fromArray($credential->secrets['service_account']), $credential->config['project_id'], $credential->config['region'], $credential->config['model']),
            default => app(AnthropicKeyValidator::class)->check($credential->secrets['api_key']),
        };
        abort_if($outcome === AnthropicKeyValidator::UNREACHABLE, 503, $message);

        $valid = $outcome === AnthropicKeyValidator::VALID;
        $credential->update([
            'status' => $valid ? WorkspaceAiCredential::STATUS_VALID : WorkspaceAiCredential::STATUS_INVALID,
            'validation_error' => $message,
            'validated_at' => now(),
        ]);

        return $this->respond($current->get(), $credential);
    }

    public function destroy(Request $request, CurrentWorkspace $current)
    {
        $credential = WorkspaceAiCredential::activeFor($current->id());
        if ($credential) {
            AuditEvent::record('ai_credentials.removed', $current->get(), $request->user(), $credential, ['provider' => $credential->provider->value, 'hint' => $credential->secret_hint]);
            $credential->delete();
        }

        return $this->respond($current->get(), null);
    }

    private function saveAnthropic(Request $request, Workspace $workspace): WorkspaceAiCredential
    {
        $apiKey = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
        ], ['api_key.regex' => 'The API key must not contain spaces or line breaks.'])['api_key'];

        [$outcome, $message] = app(AnthropicKeyValidator::class)->check($apiKey);
        $this->refuseUnlessValid($outcome, $message, 'api_key');

        return $this->store($workspace, $request, ClaudeCredentialSource::Anthropic, ['api_key' => $apiKey], null, WorkspaceAiCredential::hintFor($apiKey));
    }

    private function saveBedrock(Request $request, Workspace $workspace): WorkspaceAiCredential
    {
        abort_unless(config('boschifai.claude.bedrock.platform_principal_arn'), 503, "Amazon Bedrock isn't available on this server yet. Contact BoschifAI support.");

        $config = $request->validate([
            'role_arn' => ['required', 'string', 'regex:'.self::ROLE_ARN],
            'region' => ['required', 'string', 'regex:'.self::AWS_REGION],
            'model' => ['required', 'string', 'regex:'.self::BEDROCK_MODEL],
        ], [
            'role_arn.regex' => 'Enter the role\'s full ARN, e.g. arn:aws:iam::123456789012:role/BoschifAIBedrock.',
            'region.regex' => 'Enter an AWS region code, e.g. us-east-1.',
            'model.regex' => 'Enter a Bedrock inference profile ID (e.g. us.anthropic.claude-sonnet-4-6) or ARN.',
        ]);

        [$outcome, $message] = $this->checkBedrock($workspace, $config);
        $this->refuseUnlessValid($outcome, $message, 'role_arn');

        // No secrets: BoschifAI assumes the role per step. The role name is the hint.
        return $this->store($workspace, $request, ClaudeCredentialSource::Bedrock, [], $config, substr(strrchr($config['role_arn'], '/'), 1, 20));
    }

    private function saveVertex(Request $request, Workspace $workspace): WorkspaceAiCredential
    {
        $input = $request->validate([
            'service_account_key' => ['required', 'string', 'max:20000'],
            'project_id' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/'],
            'region' => ['required', 'string', 'regex:'.VertexEndpoint::REGION],
            'model' => ['required', 'string', 'regex:'.self::VERTEX_MODEL],
        ], [
            'project_id.regex' => 'Enter a Google Cloud project ID, e.g. acme-claude-prod.',
            'region.regex' => 'Enter global, us, eu, or a region such as us-east5.',
            'model.regex' => 'Enter a Vertex model ID, e.g. claude-sonnet-4-5@20250929.',
        ]);

        try {
            $key = GoogleServiceAccountKey::fromJson($input['service_account_key']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['service_account_key' => $e->getMessage()]);
        }
        $config = ['project_id' => ($input['project_id'] ?? null) ?: $key->projectId(), 'region' => $input['region'], 'model' => $input['model'], 'client_email' => $key->clientEmail()];

        [$outcome, $message] = app(VertexAccessValidator::class)->check($key, $config['project_id'], $config['region'], $config['model']);
        $this->refuseUnlessValid($outcome, $message, 'service_account_key');

        // The service account's email is the hint — the key itself is never shown again.
        return $this->store($workspace, $request, ClaudeCredentialSource::Vertex, ['service_account' => $key->fields], $config, substr($key->clientEmail(), 0, 20));
    }

    /** @return array{0: string, 1: ?string} */
    private function checkBedrock(Workspace $workspace, array $config): array
    {
        return app(BedrockAccessValidator::class)->check($config['role_arn'], $workspace->aiExternalId(), $config['region'], $config['model'], "boschifai-{$workspace->id}-check");
    }

    private function refuseUnlessValid(string $outcome, ?string $message, string $field): void
    {
        if ($outcome === AnthropicKeyValidator::REJECTED) {
            throw ValidationException::withMessages([$field => $message]);
        }
        abort_if($outcome === AnthropicKeyValidator::UNREACHABLE, 503, $message);
    }

    private function store(Workspace $workspace, Request $request, ClaudeCredentialSource $provider, array $secrets, ?array $config, string $hint): WorkspaceAiCredential
    {
        return WorkspaceAiCredential::updateOrCreate(['workspace_id' => $workspace->id], [
            'provider' => $provider,
            'secrets' => $secrets,
            'config' => $config,
            'secret_hint' => $hint,
            'status' => WorkspaceAiCredential::STATUS_VALID,
            'validation_error' => null,
            'validated_at' => now(),
            'updated_by_user_id' => $request->user()->id,
        ]);
    }

    private function respond(Workspace $workspace, ?WorkspaceAiCredential $credential)
    {
        $principal = config('boschifai.claude.bedrock.platform_principal_arn');

        return response()->json(['data' => [
            ...($credential ? [
                'provider' => $credential->provider->value,
                'secret_hint' => $credential->secret_hint,
                // Bedrock's and Vertex's settings aren't secret (role, project, region, model,
                // service-account email) — the Vertex key itself never leaves.
                'config' => $credential->provider === ClaudeCredentialSource::Anthropic ? null : $credential->config,
                'status' => $credential->status,
                'validation_error' => $credential->validation_error,
                'validated_at' => $credential->validated_at,
                'updated_by' => $credential->updatedBy?->name,
            ] : ['provider' => 'platform']),
            // What the customer needs to set up their role's trust policy.
            'bedrock' => $principal
                ? ['available' => true, 'principal_arn' => $principal, 'external_id' => $workspace->aiExternalId()]
                : ['available' => false],
        ]]);
    }
}
