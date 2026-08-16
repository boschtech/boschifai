<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Jobs\RunFixFailingTestsJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunFixFailingTestsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(RunState $state): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'generated_file_path' => 'tests/Feature/XTest.php',
            'state' => $state,
        ]);
    }

    public function test_fix_dispatches_the_job_and_moves_to_generation_running(): void
    {
        Queue::fake([RunFixFailingTestsJob::class]);
        $run = $this->makeRun(RunState::LocalExecutionComplete);
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 13, 'passed' => 11, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 13439,
            'tests' => [
                ['name' => 'ExampleTest::it_passes', 'status' => 'passed', 'message' => null],
                ['name' => 'ExampleTest::it_fails', 'status' => 'failed', 'message' => 'Expected true, got false.'],
            ],
        ]);

        $this->postJson("/api/runs/{$run->id}/local-execution/fix-failing-tests")
            ->assertOk()
            ->assertJsonPath('data.state', 'generation_running');

        Queue::assertPushed(RunFixFailingTestsJob::class);
    }

    public function test_fix_is_rejected_for_a_run_not_awaiting_gate_2(): void
    {
        Queue::fake([RunFixFailingTestsJob::class]);
        $run = $this->makeRun(RunState::Draft);

        $this->postJson("/api/runs/{$run->id}/local-execution/fix-failing-tests")->assertStatus(422);

        Queue::assertNotPushed(RunFixFailingTestsJob::class);
    }

    public function test_fix_is_rejected_when_there_are_no_failing_tests(): void
    {
        Queue::fake([RunFixFailingTestsJob::class]);
        $run = $this->makeRun(RunState::LocalExecutionComplete);
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 13, 'passed' => 13, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 13439,
        ]);

        $this->postJson("/api/runs/{$run->id}/local-execution/fix-failing-tests")->assertStatus(422);

        Queue::assertNotPushed(RunFixFailingTestsJob::class);
    }
}
