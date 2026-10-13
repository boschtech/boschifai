<?php

namespace Tests\Support;

use App\Services\ClaudeRunner\Credentials\BedrockAccessValidator;
use App\Services\ClaudeRunner\Credentials\BedrockRoleAssumer;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sts\StsClient;

/**
 * Stands in for AWS STS and Bedrock Runtime (the SDK's own MockHandler — no network). Queue
 * answers with sts()/bedrock(); every call's parameters are recorded in $calls.
 */
class FakeAws
{
    public const ACCESS_KEY = 'ASIAFAKEACCESSKEY123';

    public const SECRET = 'fake-secret-access-key-abcdefghijklmnop';

    public const TOKEN = 'fake-session-token-qrstuvwxyz0123456789';

    /** @var list<array{0: string, 1: array}> [operation, params] */
    public array $calls = [];

    private MockHandler $stsHandler;

    private MockHandler $bedrockHandler;

    public function __construct()
    {
        $this->stsHandler = new MockHandler;
        $this->bedrockHandler = new MockHandler;
    }

    /** Binds fake-backed STS/Bedrock clients into the container. */
    public function install($app): self
    {
        $app->instance(BedrockRoleAssumer::class, $assumer = new BedrockRoleAssumer(fn (string $region) => new StsClient([
            'region' => $region, 'version' => '2011-06-15', 'credentials' => false, 'retries' => 0, 'handler' => $this->stsHandler,
        ])));
        $app->instance(BedrockAccessValidator::class, new BedrockAccessValidator($assumer, fn (string $region, array $credentials) => new BedrockRuntimeClient([
            'region' => $region, 'version' => '2023-09-30', 'credentials' => false, 'retries' => 0, 'handler' => $this->bedrockHandler,
        ])));

        return $this;
    }

    /** Queues STS answers: true = credentials, a string = that AWS error code. */
    public function sts(bool|string ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->stsHandler->append(function (CommandInterface $command) use ($answer) {
                $this->calls[] = [$command->getName(), $command->toArray()];

                return $answer === true
                    ? new Result(['Credentials' => ['AccessKeyId' => self::ACCESS_KEY, 'SecretAccessKey' => self::SECRET, 'SessionToken' => self::TOKEN]])
                    : new AwsException("AWS said {$answer} for account 123456789012", $command, ['code' => $answer]);
            });
        }

        return $this;
    }

    /** Queues Bedrock InvokeModel answers: true = OK, a string = that AWS error code. */
    public function bedrock(bool|string ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->bedrockHandler->append(function (CommandInterface $command) use ($answer) {
                $this->calls[] = [$command->getName(), $command->toArray()];

                return $answer === true
                    ? new Result(['body' => '{"content":[{"type":"text","text":"OK"}]}', 'contentType' => 'application/json'])
                    : new AwsException("Bedrock said {$answer} for account 123456789012", $command, ['code' => $answer]);
            });
        }

        return $this;
    }
}
