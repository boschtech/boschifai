<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\RunLocalTestExecutionJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\TestExecution\JunitXmlParser;
use App\Services\TestExecution\LocalTestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RunLocalTestExecutionJob's own orchestration — LocalTestRunner (which talks to the
 * test-runner sidecar) is replaced with a stub so only the job's state handling is exercised.
 */
class RunLocalTestExecutionJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array> captured run() calls */
    private array $runnerCalls = [];

    private function makeRun(array $attributes = []): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => '/tmp/local-checkout', 'base_branch' => 'main',
        ]);

        return Run::create(array_merge([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/X.php',
            'generated_file_path' => 'tests/Feature/XTest.php',
            'execution_recipe' => ['install_command' => 'npm ci', 'test_command' => 'npm test'],
            'state' => RunState::LocalExecutionRunning,
        ], $attributes));
    }

    /** @param array|\Throwable|callable $outcome */
    private function stubRunner(mixed $outcome): void
    {
        $calls = &$this->runnerCalls;

        $this->app->instance(LocalTestRunner::class, new class(new JunitXmlParser, $outcome, $calls) extends LocalTestRunner
        {
            public function __construct(JunitXmlParser $parser, private mixed $outcome, private array &$calls)
            {
                parent::__construct($parser);
            }

            public function run($run, string $checkoutPath, string $generatedTestRelativePath, callable $isCancelled, ?array $recipe = null): array
            {
                $this->calls[] = compact('checkoutPath', 'generatedTestRelativePath', 'recipe') + ['cancelled_now' => $isCancelled()];

                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        });
    }

    private function runnerResult(array $overrides = []): array
    {
        return array_merge([
            'cancelled' => false, 'total' => 4, 'passed' => 3, 'failed' => 1, 'skipped' => 0,
            'duration_ms' => 1234, 'tests' => [['name' => 'XTest::a', 'status' => 'passed', 'message' => null]],
            'junit_xml_path' => '.boschifai/junit.xml', 'coverage_report_path' => null,
        ], $overrides);
    }

    public function test_a_completed_run_stores_the_result_and_marks_the_step_succeeded(): void
    {
        $this->stubRunner($this->runnerResult());
        $run = $this->makeRun();

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $this->assertSame([[
            'checkoutPath' => '/tmp/local-checkout',
            'generatedTestRelativePath' => 'tests/Feature/XTest.php',
            'recipe' => ['install_command' => 'npm ci', 'test_command' => 'npm test'],
            'cancelled_now' => false,
        ]], $this->runnerCalls);

        $run->refresh();
        $this->assertSame(RunState::LocalExecutionComplete, $run->state);
        $this->assertDatabaseHas('test_execution_results', [
            'run_id' => $run->id, 'total' => 4, 'passed' => 3, 'failed' => 1, 'duration_ms' => 1234,
        ]);

        $step = $run->steps()->where('key', 'local_execution')->first();
        $this->assertSame(RunStepStatus::Succeeded, $step->status);
        $this->assertNotNull($step->started_at);
        $this->assertNotNull($step->finished_at);
    }

    public function test_a_rerun_reuses_the_existing_step_row(): void
    {
        $this->stubRunner($this->runnerResult());
        $run = $this->makeRun();
        RunStep::create([
            'run_id' => $run->id, 'key' => 'local_execution', 'status' => RunStepStatus::Failed,
            'finished_at' => now()->subHour(),
        ]);

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $this->assertSame(1, $run->steps()->where('key', 'local_execution')->count());
        $this->assertSame(RunStepStatus::Succeeded, $run->steps()->first()->status);
    }

    public function test_a_runner_exception_fails_the_step_and_the_run(): void
    {
        $this->stubRunner(new \RuntimeException('The test-runner sidecar did not report back'));
        $run = $this->makeRun();

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('local_execution', $run->failed_step);
        $this->assertSame('The test-runner sidecar did not report back', $run->error_message);
        $step = $run->steps()->where('key', 'local_execution')->first();
        $this->assertSame(RunStepStatus::Failed, $step->status);
        $this->assertSame('The test-runner sidecar did not report back', $step->error_message);
        $this->assertDatabaseCount('test_execution_results', 0);
    }

    public function test_an_exception_with_no_message_falls_back_to_its_class_name(): void
    {
        $this->stubRunner(new \LogicException);
        $run = $this->makeRun();

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $this->assertSame('LogicException', $run->fresh()->error_message);
    }

    public function test_a_cancellation_reported_by_the_runner_marks_the_run_cancelled_without_a_result(): void
    {
        $this->stubRunner($this->runnerResult(['cancelled' => true]));
        $run = $this->makeRun();

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Cancelled, $run->state);
        $this->assertDatabaseCount('test_execution_results', 0);
        $this->assertSame(RunStepStatus::Cancelled, $run->steps()->where('key', 'local_execution')->first()->status);
    }

    public function test_a_cancellation_requested_before_start_never_invokes_the_runner(): void
    {
        $this->stubRunner($this->runnerResult());
        $run = $this->makeRun(['cancel_requested_at' => now()]);

        app()->call([new RunLocalTestExecutionJob($run->id), 'handle']);

        $this->assertSame([], $this->runnerCalls);
        $this->assertSame(RunState::Cancelled, $run->fresh()->state);
        $this->assertDatabaseCount('test_execution_results', 0);
    }
}
