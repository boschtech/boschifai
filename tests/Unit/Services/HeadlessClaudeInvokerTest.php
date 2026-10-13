<?php

namespace Tests\Unit\Services;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Services\ClaudeRunner\ClaudeProcessHardening;
use App\Services\ClaudeRunner\Credentials\ResolvedClaudeCredentials;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * HeadlessClaudeInvoker's command construction and polling loop, with `claude` always faked.
 */
class HeadlessClaudeInvokerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/boschifai-invoker-test-'.uniqid();
        config(['boschifai.github_mcp.pat' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function invoker(): HeadlessClaudeInvoker
    {
        return app(HeadlessClaudeInvoker::class);
    }

    private function credentials(array $env = ['ANTHROPIC_API_KEY' => 'sk-test'], array $secrets = ['sk-test']): ResolvedClaudeCredentials
    {
        return new ResolvedClaudeCredentials($env, 'test-model', ClaudeBillingMode::Platform, ClaudeCredentialSource::Platform, $secrets);
    }

    public function test_a_successful_invocation_writes_the_transcript_and_parses_its_summary(): void
    {
        $output = json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'hi']]]])."\n"
            .json_encode(['type' => 'result', 'subtype' => 'success', 'total_cost_usd' => 0.25, 'num_turns' => 4, 'usage' => ['input_tokens' => 11, 'output_tokens' => 22]])."\n";

        Process::fake(['*' => Process::result(output: $output, errorOutput: 'some warning')]);

        $transcript = $this->dir.'/nested/transcript.jsonl';
        $result = $this->invoker()->invoke($this->dir, 'Do the thing', $transcript, 60, fn () => false, $this->credentials());

        $this->assertTrue($result->succeeded());
        $this->assertSame(0, $result->exitCode);
        $this->assertFalse($result->timedOut);
        $this->assertFalse($result->cancelled);
        $this->assertSame(0.25, $result->totalCostUsd);
        $this->assertSame(4, $result->numTurns);
        $this->assertSame(11, $result->inputTokens);
        $this->assertSame(22, $result->outputTokens);
        $this->assertSame('some warning', trim($result->stderr));
        $this->assertSame($transcript, $result->rawTranscriptPath);
        $this->assertSame($output, File::get($transcript));

        Process::assertRan(function ($process) {
            return array_slice($process->command, 0, 10) === [
                'claude', '-p', 'Do the thing',
                '--output-format', 'stream-json',
                '--verbose',
                '--permission-mode', 'acceptEdits',
                '--model', 'test-model',
            ] && array_slice($process->command, 10) === app(ClaudeProcessHardening::class)->cliArguments()
                && $process->environment['ANTHROPIC_API_KEY'] === 'sk-test'
                && $process->timeout === 60;
        });
    }

    public function test_the_agent_process_never_inherits_the_workers_secrets(): void
    {
        $_SERVER['DB_PASSWORD'] = 'db-secret';
        $_ENV['APP_KEY'] = 'base64:app-secret';
        $_SERVER['BOSCHIFAI_CREDENTIALS_KEY'] = 'base64:credentials-secret';
        Process::fake();

        try {
            $this->invoker()->invoke($this->dir, 'gen', $this->dir.'/a.jsonl', 60, fn () => false, $this->credentials(), 'code_generation');
        } finally {
            unset($_SERVER['DB_PASSWORD'], $_ENV['APP_KEY'], $_SERVER['BOSCHIFAI_CREDENTIALS_KEY']);
        }

        Process::assertRan(fn ($process) => $process->environment['DB_PASSWORD'] === false
            && $process->environment['APP_KEY'] === false
            && $process->environment['BOSCHIFAI_CREDENTIALS_KEY'] === false
            && $process->environment['ANTHROPIC_API_KEY'] === 'sk-test'
            && ! in_array('db-secret', $process->environment, true)
            && in_array('--strict-mcp-config', $process->command, true)
            && in_array('--setting-sources', $process->command, true));
    }

    public function test_the_process_runs_with_whatever_credentials_and_model_were_resolved(): void
    {
        Process::fake();
        $credentials = new ResolvedClaudeCredentials(
            ['CLAUDE_CODE_USE_BEDROCK' => '1', 'AWS_REGION' => 'eu-west-1'],
            'bedrock-model-id',
            ClaudeBillingMode::Platform,
            ClaudeCredentialSource::Platform,
        );

        $this->invoker()->invoke($this->dir, 'gen', $this->dir.'/a.jsonl', 60, fn () => false, $credentials, 'code_generation');

        Process::assertRan(fn ($process) => $process->environment['CLAUDE_CODE_USE_BEDROCK'] === '1'
            && $process->environment['AWS_REGION'] === 'eu-west-1'
            && ! array_key_exists('ANTHROPIC_API_KEY', array_filter($process->environment))
            && $process->command[array_search('--model', $process->command, true) + 1] === 'bedrock-model-id');
    }

    public function test_credentials_the_agent_prints_never_reach_the_transcript_or_error_output(): void
    {
        config(['boschifai.github_mcp.pat' => 'ghp_pushtoken123', 'boschifai.github_mcp.config_path' => '/etc/mcp.json']);
        $output = json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => "ANTHROPIC_API_KEY=sk-ant-real-key-9\nGITHUB_PERSONAL_ACCESS_TOKEN=ghp_pushtoken123"]]]])."\n"
            .json_encode(['type' => 'result', 'subtype' => 'success', 'total_cost_usd' => 0.1, 'num_turns' => 1])."\n";
        Process::fake(['*' => Process::result(output: $output, errorOutput: 'auth failed for sk-ant-real-key-9')]);
        $transcript = $this->dir.'/t.jsonl';

        $result = $this->invoker()->invoke($this->dir, 'push', $transcript, 60, fn () => false, $this->credentials(['ANTHROPIC_API_KEY' => 'sk-ant-real-key-9'], ['sk-ant-real-key-9']), 'push');

        $stored = File::get($transcript);
        $this->assertStringNotContainsString('sk-ant-real-key-9', $stored);
        $this->assertStringNotContainsString('ghp_pushtoken123', $stored);
        $this->assertStringContainsString('ANTHROPIC_API_KEY=[REDACTED]', $stored);
        $this->assertSame('auth failed for [REDACTED]', trim($result->stderr));
        // Still valid JSON lines, so the summary is parsed as before.
        $this->assertSame(0.1, $result->totalCostUsd);
    }

    public function test_only_the_push_step_gets_the_github_token_and_mcp_server_with_an_allowlist(): void
    {
        config(['boschifai.github_mcp.pat' => 'ghp_test', 'boschifai.github_mcp.config_path' => '/etc/mcp.json']);
        Process::fake();

        $this->invoker()->invoke($this->dir, 'gen', $this->dir.'/a.jsonl', 60, fn () => false, $this->credentials(), 'code_generation');
        $this->invoker()->invoke($this->dir, 'push', $this->dir.'/b.jsonl', 60, fn () => false, $this->credentials(), 'push');

        // Every other step's agent never sees the push credential or the GitHub MCP server.
        Process::assertRan(fn ($process) => in_array('gen', $process->command, true)
            && ! in_array('--mcp-config', $process->command, true)
            && ! in_array('--allowedTools', $process->command, true)
            && ! in_array('ghp_test', $process->environment, true));

        Process::assertRan(fn ($process) => in_array('push', $process->command, true)
            && in_array('/etc/mcp.json', $process->command, true)
            && $process->environment['GITHUB_PERSONAL_ACCESS_TOKEN'] === 'ghp_test');

        Process::assertRan(function ($process) {
            if (! in_array('push', $process->command, true)) {
                return false;
            }
            $index = array_search('--allowedTools', $process->command, true);

            return $index !== false && $process->command[$index + 1]
                === 'mcp__github__create_branch,mcp__github__create_or_update_file,mcp__github__push_files,mcp__github__create_pull_request';
        });
    }

    /**
     * Laravel's FakeInvokedProcess has no stop() method, so the deadline/cancel branches are
     * driven through a mocked PendingProcess/InvokedProcess pair instead of Process::fake().
     *
     * @param  bool|callable  $runningSequence  values returned by successive running() calls
     */
    private function swapInvokedProcess(array $runningSequence, callable $wait, bool $expectStop): void
    {
        $invoked = Mockery::mock(InvokedProcess::class);
        $invoked->shouldReceive('running')->andReturn(...$runningSequence);
        $invoked->shouldReceive('wait')->andReturnUsing($wait);
        if ($expectStop) {
            $invoked->shouldReceive('stop')->once()->with(3);
        } else {
            $invoked->shouldNotReceive('stop');
        }

        $pending = Mockery::mock(PendingProcess::class);
        $pending->shouldReceive('path', 'timeout', 'env')->andReturnSelf();
        $pending->shouldReceive('start')->andReturn($invoked);

        $factory = Mockery::mock(Factory::class)->makePartial();
        $factory->shouldReceive('newPendingProcess')->andReturn($pending);
        Process::swap($factory);
    }

    private function fakeResult(int $exitCode): ProcessResult
    {
        return new FakeProcessResult(exitCode: $exitCode);
    }

    public function test_a_process_still_running_past_its_deadline_is_stopped_and_reported_as_timed_out(): void
    {
        $this->swapInvokedProcess([true], fn () => $this->fakeResult(143), expectStop: true);
        $transcript = $this->dir.'/t.jsonl';

        $result = $this->invoker()->invoke($this->dir, 'slow', $transcript, 0, fn () => false, $this->credentials());

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->cancelled);
        $this->assertFalse($result->succeeded());
        $this->assertSame(143, $result->exitCode);
        // Summary deliberately not parsed for a timed-out invocation.
        $this->assertNull($result->totalCostUsd);
        $this->assertStringContainsString('Timed out', $result->failureReason());
    }

    public function test_a_cancellation_request_stops_the_process_and_reports_cancelled(): void
    {
        $this->swapInvokedProcess([true], fn () => $this->fakeResult(143), expectStop: true);

        $result = $this->invoker()->invoke($this->dir, 'cancel me', $this->dir.'/t.jsonl', 600, fn () => true, $this->credentials());

        $this->assertTrue($result->cancelled);
        $this->assertFalse($result->timedOut);
        $this->assertFalse($result->succeeded());
    }

    public function test_symfonys_own_timeout_firing_on_wait_is_reported_as_timed_out(): void
    {
        $this->swapInvokedProcess([false], function () {
            $symfony = new SymfonyProcess(['claude']);

            throw new ProcessTimedOutException(
                new SymfonyTimeoutException($symfony, SymfonyTimeoutException::TYPE_GENERAL),
                $this->fakeResult(-1),
            );
        }, expectStop: false);

        $result = $this->invoker()->invoke($this->dir, 'x', $this->dir.'/t.jsonl', 600, fn () => false, $this->credentials());

        $this->assertTrue($result->timedOut);
        $this->assertSame(-1, $result->exitCode);
        $this->assertStringContainsString('exceeded the timeout', $result->stderr);
    }

    public function test_the_loop_keeps_polling_while_the_process_runs_and_not_cancelled(): void
    {
        $checks = 0;
        Process::fake(['*' => Process::describe()->exitCode(3)->errorOutput('crashed')->runsFor(iterations: 1)]);

        $result = $this->invoker()->invoke($this->dir, 'run', $this->dir.'/t.jsonl', 600, function () use (&$checks) {
            $checks++;

            return false;
        }, $this->credentials());

        $this->assertSame(1, $checks);
        $this->assertSame(3, $result->exitCode);
        $this->assertFalse($result->cancelled);
        $this->assertFalse($result->succeeded());
        $this->assertSame('crashed', trim($result->stderr));
    }

    public function test_transcript_path_for_is_under_the_run_directory_and_unique(): void
    {
        $a = $this->invoker()->transcriptPathFor('run-1', 'gap_analysis');
        $b = $this->invoker()->transcriptPathFor('run-1', 'gap_analysis');

        $this->assertStringStartsWith(storage_path('app/boschifai-runs/run-1/claude/gap_analysis-'), $a);
        $this->assertStringEndsWith('.jsonl', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_the_push_step_gets_no_github_token_when_mcp_push_is_disabled(): void
    {
        config(['boschifai.github_mcp.pat' => 'ghp_test', 'boschifai.features.github_mcp_push' => false]);
        Process::fake();

        $this->invoker()->invoke($this->dir, 'push', $this->dir.'/b.jsonl', 60, fn () => false, $this->credentials(), 'push');

        Process::assertRan(fn ($process) => ! in_array('--mcp-config', $process->command, true)
            && ! in_array('ghp_test', $process->environment, true));
    }
}
