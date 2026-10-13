<?php

namespace App\Services\ClaudeRunner\Credentials;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks a workspace's Vertex setup end to end before it's saved: the service-account key is
 * accepted by Google, and it can invoke the chosen Claude model in that project and region. The
 * second part is a real 1-token request — the only way to prove it — billed to the workspace's
 * Google Cloud project (a fraction of a cent). Google's own error text isn't passed on.
 */
class VertexAccessValidator
{
    public const VALID = 'valid';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /** @return array{0: string, 1: ?string} [outcome, message for people] */
    public function check(GoogleServiceAccountKey $key, string $projectId, string $region, string $model): array
    {
        try {
            $token = Http::asForm()->timeout(15)->post(GoogleServiceAccountKey::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $key->signedAssertion(time()),
            ]);
            if ($token->clientError()) {
                return [self::REJECTED, 'Google rejected this service-account key. Check that the key and its service account are still enabled.'];
            }
            if (! $token->successful() || ! is_string($token->json('access_token'))) {
                return [self::UNREACHABLE, "Google couldn't check the key right now. Nothing was changed — try again in a moment."];
            }

            $response = Http::withToken($token->json('access_token'))->acceptJson()->timeout(30)->post(
                VertexEndpoint::baseUrl($region)."/projects/{$projectId}/locations/{$region}/publishers/anthropic/models/{$model}:rawPredict",
                ['anthropic_version' => 'vertex-2023-10-16', 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'Reply with OK.']]],
            );
        } catch (ConnectionException) {
            return [self::UNREACHABLE, "Couldn't reach Google Cloud to check the setup. Nothing was changed — try again in a moment."];
        }

        return match (true) {
            $response->successful() => [self::VALID, null],
            in_array($response->status(), [401, 403], true) => [self::REJECTED, "The service account can't use Vertex AI in this project. Give it the Vertex AI User role (roles/aiplatform.user) and check the Vertex AI API is enabled."],
            in_array($response->status(), [400, 404], true) => [self::REJECTED, "Vertex AI doesn't offer this model here. Check the model ID (e.g. claude-sonnet-4-5@20250929), the region, and that the model is enabled in Model Garden."],
            $response->status() === 429 => [self::UNREACHABLE, 'Vertex AI refused the check for quota reasons. Check your Claude quota in this region, then try again.'],
            default => [self::UNREACHABLE, "Vertex AI couldn't be checked right now (HTTP {$response->status()}). Nothing was changed — try again in a moment."],
        };
    }
}
