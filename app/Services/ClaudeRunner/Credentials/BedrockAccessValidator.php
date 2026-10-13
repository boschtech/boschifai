<?php

namespace App\Services\ClaudeRunner\Credentials;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Closure;

/**
 * Checks a workspace's Bedrock setup end to end before it's saved: BoschifAI can assume the role
 * (trust policy + external ID), and that role can invoke the chosen model in that region (IAM
 * permissions + model access enabled). The second part is a real 1-token request — the only way
 * to prove it — billed to the workspace's AWS account (a fraction of a cent).
 */
class BedrockAccessValidator
{
    public const VALID = 'valid';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /** @param  (Closure(string, array): BedrockRuntimeClient)|null  $runtimeFactory  (region, credentials) → client */
    public function __construct(
        private BedrockRoleAssumer $roles,
        private ?Closure $runtimeFactory = null,
    ) {}

    /** @return array{0: string, 1: ?string} [outcome, message for people] */
    public function check(string $roleArn, string $externalId, string $region, string $model, string $sessionName): array
    {
        try {
            $credentials = $this->roles->assume($roleArn, $externalId, $region, $sessionName, 900);
        } catch (BedrockAccessDenied $e) {
            return [self::REJECTED, $e->getMessage()];
        }

        try {
            $this->runtime($region, $credentials)->invokeModel([
                'modelId' => $model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode([
                    'anthropic_version' => 'bedrock-2023-05-31',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
                ]),
            ]);
        } catch (AwsException $e) {
            return self::explain($e);
        }

        return [self::VALID, null];
    }

    /** @return array{0: string, 1: string} — AWS's own text can include account details, so it isn't passed on */
    private static function explain(AwsException $e): array
    {
        return match ($e->getAwsErrorCode()) {
            'AccessDeniedException' => [self::REJECTED, "The role can't invoke this model. Check the role's Bedrock permissions and that access to the model is enabled in the Bedrock console for this region."],
            'ValidationException', 'ResourceNotFoundException' => [self::REJECTED, "Bedrock doesn't recognise this model in this region. Use an inference profile ID such as us.anthropic.claude-sonnet-4-6, or an inference profile ARN."],
            default => [self::UNREACHABLE, "Bedrock couldn't be checked right now ({$e->getAwsErrorCode()}). Nothing was changed — try again in a moment."],
        };
    }

    private function runtime(string $region, array $credentials): BedrockRuntimeClient
    {
        if ($this->runtimeFactory) {
            return ($this->runtimeFactory)($region, $credentials);
        }

        return new BedrockRuntimeClient([
            'region' => $region,
            'version' => '2023-09-30',
            'credentials' => ['key' => $credentials['access_key_id'], 'secret' => $credentials['secret_access_key'], 'token' => $credentials['session_token']],
        ]);
    }
}
