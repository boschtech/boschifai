<?php

namespace App\Services\ClaudeRunner\Credentials;

use Aws\Exception\AwsException;
use Aws\Sts\StsClient;
use Closure;
use RuntimeException;

/**
 * Gets short-lived AWS credentials for a workspace's Bedrock role (STS AssumeRole), so no
 * long-lived AWS keys are ever stored. BoschifAI's own AWS identity (the ECS task role, or the
 * default credential chain elsewhere) must be allowed to assume the role, and the role's trust
 * policy must require the workspace's external ID — that stops one customer from getting
 * BoschifAI to use another customer's role ("confused deputy").
 */
class BedrockRoleAssumer
{
    /** @param  (Closure(string): StsClient)|null  $clientFactory  region → client (tests swap it) */
    public function __construct(private ?Closure $clientFactory = null) {}

    /**
     * @return array{access_key_id: string, secret_access_key: string, session_token: string}
     *
     * @throws BedrockAccessDenied when AWS refuses (wrong ARN, trust policy, external ID…)
     */
    public function assume(string $roleArn, string $externalId, string $region, string $sessionName, int $durationSeconds): array
    {
        try {
            $result = $this->client($region)->assumeRole([
                'RoleArn' => $roleArn,
                'ExternalId' => $externalId,
                // AWS: 2–64 characters of [\w+=,.@-].
                'RoleSessionName' => substr('boschifai-'.preg_replace('/[^\w+=,.@-]/', '-', preg_replace('/^boschifai-/', '', $sessionName)), 0, 64),
                // AWS allows 900 s up to the role's maximum session (1 hour by default).
                'DurationSeconds' => max(900, min($durationSeconds, 3600)),
            ]);
        } catch (AwsException $e) {
            throw new BedrockAccessDenied(self::explain($e), previous: $e);
        }

        $credentials = $result['Credentials'] ?? null;
        if (! is_array($credentials) || empty($credentials['AccessKeyId']) || empty($credentials['SecretAccessKey']) || empty($credentials['SessionToken'])) {
            throw new RuntimeException('AWS STS returned no credentials for the Bedrock role.');
        }

        return [
            'access_key_id' => $credentials['AccessKeyId'],
            'secret_access_key' => $credentials['SecretAccessKey'],
            'session_token' => $credentials['SessionToken'],
        ];
    }

    /** A message for people — AWS's own text can include account details, so it isn't passed on. */
    private static function explain(AwsException $e): string
    {
        return match ($e->getAwsErrorCode()) {
            'AccessDenied' => "AWS didn't let BoschifAI assume this role. Check the role ARN, and that its trust policy allows BoschifAI's AWS account with this workspace's external ID.",
            'ValidationError' => 'AWS rejected the role details. Check that the role ARN is complete and correct.',
            'RegionDisabledException' => 'AWS STS is not enabled in this region for the role\'s account. Enable it or choose another region.',
            default => "AWS couldn't provide credentials for this role right now ({$e->getAwsErrorCode()}). Try again in a moment.",
        };
    }

    private function client(string $region): StsClient
    {
        return $this->clientFactory
            ? ($this->clientFactory)($region)
            : new StsClient(['region' => $region, 'version' => '2011-06-15']);
    }
}
