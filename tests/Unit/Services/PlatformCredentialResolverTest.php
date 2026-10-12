<?php

namespace Tests\Unit\Services;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Models\Run;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\PlatformCredentialResolver;
use Tests\TestCase;

class PlatformCredentialResolverTest extends TestCase
{
    public function test_every_step_runs_on_the_platform_key_and_model(): void
    {
        config(['boschifai.claude.api_key' => 'sk-ant-platform-key', 'boschifai.claude.model' => 'claude-test-model']);

        $credentials = app(ClaudeCredentialResolver::class)->resolveFor(new Run, 'gap_analysis');

        $this->assertInstanceOf(PlatformCredentialResolver::class, app(ClaudeCredentialResolver::class));
        $this->assertSame(['ANTHROPIC_API_KEY' => 'sk-ant-platform-key'], $credentials->env);
        $this->assertSame('claude-test-model', $credentials->model);
        $this->assertSame(ClaudeBillingMode::Platform, $credentials->billingMode);
        $this->assertSame(ClaudeCredentialSource::Platform, $credentials->source);
        $this->assertSame(['sk-ant-platform-key'], $credentials->secretsToRedact);
        $credentials->cleanup(); // nothing to clean up — must not fail
    }

    public function test_without_a_key_nothing_is_set_so_a_cli_sign_in_can_still_be_used(): void
    {
        config(['boschifai.claude.api_key' => null]);

        $credentials = (new PlatformCredentialResolver)->resolveFor(new Run, 'gap_analysis');

        $this->assertSame(['ANTHROPIC_API_KEY' => null], $credentials->env);
        $this->assertSame([], $credentials->secretsToRedact);
    }
}
