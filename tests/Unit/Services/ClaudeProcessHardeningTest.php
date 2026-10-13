<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\ClaudeProcessHardening;
use Tests\TestCase;

class ClaudeProcessHardeningTest extends TestCase
{
    private const WORKER_ENV = [
        'PATH' => '/usr/local/bin:/usr/bin',
        'HOME' => '/root',
        'LANG' => 'C.UTF-8',
        'HTTPS_PROXY' => 'http://corp-proxy:3128',
        'DB_PASSWORD' => 'db-secret',
        'APP_KEY' => 'base64:app-secret',
        'ANTHROPIC_API_KEY' => 'sk-platform-from-worker',
        'GITHUB_MCP_PAT' => 'ghp_worker',
        'AWS_SECRET_ACCESS_KEY' => 'aws-secret',
        'SOME_FUTURE_SECRET' => 'unknown-to-us',
    ];

    private function hardening(): ClaudeProcessHardening
    {
        return new ClaudeProcessHardening;
    }

    private function settings(array $arguments): array
    {
        return json_decode($arguments[array_search('--settings', $arguments, true) + 1], true);
    }

    public function test_keeps_only_allowlisted_variables_and_removes_everything_else(): void
    {
        $env = $this->hardening()->environment([], self::WORKER_ENV);

        $this->assertSame('/usr/local/bin:/usr/bin', $env['PATH']);
        $this->assertSame('/root', $env['HOME']);
        $this->assertSame('C.UTF-8', $env['LANG']);
        $this->assertSame('http://corp-proxy:3128', $env['HTTPS_PROXY']);

        // Removal is explicit (false), so nothing reaches the child by inheritance — including
        // secrets added to the worker later that this class has never heard of.
        foreach (['DB_PASSWORD', 'APP_KEY', 'ANTHROPIC_API_KEY', 'GITHUB_MCP_PAT', 'AWS_SECRET_ACCESS_KEY', 'SOME_FUTURE_SECRET'] as $secret) {
            $this->assertFalse($env[$secret], $secret);
        }
    }

    public function test_passes_this_invocations_credentials_and_drops_empty_ones(): void
    {
        $env = $this->hardening()->environment([
            'ANTHROPIC_API_KEY' => 'sk-for-this-run',
            'GITHUB_PERSONAL_ACCESS_TOKEN' => null,
            'EMPTY' => '',
        ], self::WORKER_ENV);

        $this->assertSame('sk-for-this-run', $env['ANTHROPIC_API_KEY']);
        $this->assertArrayNotHasKey('GITHUB_PERSONAL_ACCESS_TOKEN', $env);
        $this->assertArrayNotHasKey('EMPTY', $env);
    }

    public function test_extra_variables_can_be_passed_through_by_configuration(): void
    {
        config(['boschifai.claude.hardening.env_passthrough' => ['SOME_FUTURE_SECRET']]);

        $env = $this->hardening()->environment([], self::WORKER_ENV);

        $this->assertSame('unknown-to-us', $env['SOME_FUTURE_SECRET']);
        $this->assertFalse($env['DB_PASSWORD']);
    }

    public function test_reads_the_same_sources_symfony_process_inherits_from_by_default(): void
    {
        $_SERVER['BOSCHIFAI_TEST_SERVER_SECRET'] = 'from-server';
        $_ENV['BOSCHIFAI_TEST_ENV_SECRET'] = 'from-env';
        putenv('BOSCHIFAI_TEST_GETENV_SECRET=from-getenv');

        try {
            $env = $this->hardening()->environment([]);
        } finally {
            unset($_SERVER['BOSCHIFAI_TEST_SERVER_SECRET'], $_ENV['BOSCHIFAI_TEST_ENV_SECRET']);
            putenv('BOSCHIFAI_TEST_GETENV_SECRET');
        }

        $this->assertFalse($env['BOSCHIFAI_TEST_SERVER_SECRET']);
        $this->assertFalse($env['BOSCHIFAI_TEST_ENV_SECRET']);
        $this->assertFalse($env['BOSCHIFAI_TEST_GETENV_SECRET']);
    }

    public function test_stops_a_repository_from_reconfiguring_the_cli(): void
    {
        $arguments = $this->hardening()->cliArguments();

        // settings.local.json is ignored; project settings stay so the pipeline's own skills load.
        $this->assertSame('user,project', $arguments[array_search('--setting-sources', $arguments, true) + 1]);
        // Only MCP servers we pass explicitly — never a repo's .mcp.json.
        $this->assertContains('--strict-mcp-config', $arguments);

        $settings = $this->settings($arguments);
        $this->assertTrue($settings['disableAllHooks']);
        $this->assertSame('', $settings['apiKeyHelper']);
        $this->assertSame('https://api.anthropic.com', $settings['env']['ANTHROPIC_BASE_URL']);
        $this->assertSame('1', $settings['env']['NODE_TLS_REJECT_UNAUTHORIZED']);
        $this->assertSame('', $settings['env']['ALL_PROXY']);
        $this->assertArrayHasKey('HTTPS_PROXY', $settings['env']);
    }

    public function test_the_api_endpoint_is_configurable(): void
    {
        config(['boschifai.claude.hardening.api_base_url' => 'https://llm-gateway.internal']);

        $this->assertSame('https://llm-gateway.internal', $this->settings($this->hardening()->cliArguments())['env']['ANTHROPIC_BASE_URL']);
    }

    public function test_restricted_mode_is_opt_in(): void
    {
        $this->assertNotContains('--restricted', $this->hardening()->cliArguments());

        config(['boschifai.claude.hardening.restricted_mode' => true]);

        $this->assertContains('--restricted', $this->hardening()->cliArguments());
    }

    public function test_a_repository_cannot_switch_a_run_onto_bedrock_or_vertex(): void
    {
        // Otherwise a repo could point a platform run at its own "Bedrock" endpoint, where the
        // AWS SDK would sign requests with whatever credentials the worker's machine has.
        $env = $this->settings($this->hardening()->cliArguments())['env'];
        $this->assertSame(['0', '0'], [$env['CLAUDE_CODE_USE_BEDROCK'], $env['CLAUDE_CODE_USE_VERTEX']]);

        // Only the invocation's own credentials can turn a provider on (pinned the same way).
        $bedrock = $this->settings($this->hardening()->cliArguments(['CLAUDE_CODE_USE_BEDROCK' => '1']))['env'];
        $this->assertSame(['1', '0'], [$bedrock['CLAUDE_CODE_USE_BEDROCK'], $bedrock['CLAUDE_CODE_USE_VERTEX']]);
    }
}
