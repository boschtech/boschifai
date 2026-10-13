<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClaudeCredentialSource;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\WorkspaceAiCredential;
use App\Services\ClaudeRunner\Credentials\AnthropicKeyValidator;
use App\Support\CurrentWorkspace;
use App\Support\Security\CredentialsEncrypter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Settings → AI provider (owners and admins): run on the platform's key and credits, or on the
 * workspace's own Anthropic API key. A key is only saved once Anthropic accepts it, and is never
 * sent back — responses show a hint ("…AbCd") only.
 */
class WorkspaceAiCredentialController extends Controller
{
    public function show(CurrentWorkspace $current)
    {
        return $this->respond(WorkspaceAiCredential::activeFor($current->id()));
    }

    public function update(Request $request, CurrentWorkspace $current, AnthropicKeyValidator $validator, CredentialsEncrypter $encrypter)
    {
        // Checked before the key is sent anywhere: without the dedicated encryption key it
        // couldn't be stored safely, and there's no fallback to APP_KEY.
        if (! $encrypter->isConfigured()) {
            report(new RuntimeException('A workspace tried to save its own AI key, but BOSCHIFAI_CREDENTIALS_KEY is not set.'));
            abort(503, "Using your own API key isn't available on this server yet. Contact BoschifAI support.");
        }

        $apiKey = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
        ], ['api_key.regex' => 'The API key must not contain spaces or line breaks.'])['api_key'];

        [$outcome, $message] = $validator->check($apiKey);
        if ($outcome === AnthropicKeyValidator::REJECTED) {
            throw ValidationException::withMessages(['api_key' => $message]);
        }
        abort_if($outcome === AnthropicKeyValidator::UNREACHABLE, 503, $message);

        $credential = WorkspaceAiCredential::updateOrCreate(['workspace_id' => $current->id()], [
            'provider' => ClaudeCredentialSource::Anthropic,
            'secrets' => ['api_key' => $apiKey],
            'secret_hint' => WorkspaceAiCredential::hintFor($apiKey),
            'status' => WorkspaceAiCredential::STATUS_VALID,
            'validation_error' => null,
            'validated_at' => now(),
            'updated_by_user_id' => $request->user()->id,
        ]);
        AuditEvent::record('ai_credentials.saved', $current->get(), $request->user(), $credential, ['provider' => 'anthropic', 'hint' => $credential->secret_hint]);

        return $this->respond($credential);
    }

    /** Re-checks the stored key, e.g. after a step failed with "key rejected". */
    public function check(CurrentWorkspace $current, AnthropicKeyValidator $validator)
    {
        $credential = WorkspaceAiCredential::activeFor($current->id());
        abort_unless($credential, 404, 'This workspace uses the platform key — there is no key of its own to check.');

        [$outcome, $message] = $validator->check($credential->secrets['api_key']);
        abort_if($outcome === AnthropicKeyValidator::UNREACHABLE, 503, $message);

        $valid = $outcome === AnthropicKeyValidator::VALID;
        $credential->update([
            'status' => $valid ? WorkspaceAiCredential::STATUS_VALID : WorkspaceAiCredential::STATUS_INVALID,
            'validation_error' => $message,
            'validated_at' => now(),
        ]);

        return $this->respond($credential);
    }

    public function destroy(Request $request, CurrentWorkspace $current)
    {
        $credential = WorkspaceAiCredential::activeFor($current->id());
        if ($credential) {
            AuditEvent::record('ai_credentials.removed', $current->get(), $request->user(), $credential, ['provider' => $credential->provider->value, 'hint' => $credential->secret_hint]);
            $credential->delete();
        }

        return $this->respond(null);
    }

    private function respond(?WorkspaceAiCredential $credential)
    {
        return response()->json(['data' => $credential ? [
            'provider' => $credential->provider->value,
            'secret_hint' => $credential->secret_hint,
            'status' => $credential->status,
            'validation_error' => $credential->validation_error,
            'validated_at' => $credential->validated_at,
            'updated_by' => $credential->updatedBy?->name,
        ] : ['provider' => 'platform']]);
    }
}
