<?php

namespace Tests\Feature\Services;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Enums\RunState;
use App\Models\ClaudeInvocation;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\ResolvedClaudeCredentials;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * StepExecutionService asks the credential resolver what each step runs with, hands that to the
 * invoker, records it on the invocation, and always lets the credentials clean up.
 */
class StepExecutionCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private int $cleanups = 0;

    private ?ResolvedClaudeCredentials $passed = null;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn(sys_get_temp_dir());
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);

        $this->app->instance(ClaudeCredentialResolver::class, new class($this) implements ClaudeCredentialResolver
        {
            public function __construct(private StepExecutionCredentialsTest $test) {}

            public function resolveFor(Run $run, string $stepKey): ResolvedClaudeCredentials
            {
                return new ResolvedClaudeCredentials(
                    env: ['ANTHROPIC_API_KEY' => "key-for-{$stepKey}"],
                    model: 'claude-chosen-model',
                    billingMode: ClaudeBillingMode::Platform,
                    source: ClaudeCredentialSource::Platform,
                    cleanup: fn () => $this->test->cleanedUp(),
                );
            }
        });
    }

    public function cleanedUp(): void
    {
        $this->cleanups++;
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id,
            'name' => 'backend', 'display_name' => 'acme/backend', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main',
        ]);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x.php', 'state' => RunState::GapAnalysisRunning]);
    }

    private function invokerReturning(callable $behaviour): void
    {
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->once()->andReturnUsing(function (...$args) use ($behaviour) {
            $this->passed = $args[5];

            return $behaviour();
        });
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);
    }

    public function test_the_resolved_credentials_are_used_and_recorded_on_the_invocation(): void
    {
        $this->invokerReturning(fn () => new ClaudeInvocationResult(exitCode: 0, timedOut: false, rawTranscriptPath: '', durationMs: 5, totalCostUsd: 0.1, numTurns: 1, stopReason: 'end_turn', stderr: ''));

        $outcome = app(StepExecutionService::class)->run($this->makeRun(), 'gap_analysis', '/boschifai-review', 60);

        $this->assertTrue($outcome->claudeResult->succeeded());
        $this->assertSame(['ANTHROPIC_API_KEY' => 'key-for-gap_analysis'], $this->passed->env);
        $invocation = ClaudeInvocation::sole();
        $this->assertSame(ClaudeBillingMode::Platform, $invocation->billing_mode);
        $this->assertSame(ClaudeCredentialSource::Platform, $invocation->credential_source);
        $this->assertSame('claude-chosen-model', $invocation->model);
        $this->assertSame(1, $this->cleanups);
    }

    public function test_credentials_are_cleaned_up_even_when_the_invocation_crashes(): void
    {
        $this->invokerReturning(fn () => throw new RuntimeException('worker lost'));

        try {
            app(StepExecutionService::class)->run($this->makeRun(), 'gap_analysis', '/boschifai-review', 60);
            $this->fail('Expected the crash to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('worker lost', $e->getMessage());
        }

        $this->assertSame(1, $this->cleanups);
    }
}
