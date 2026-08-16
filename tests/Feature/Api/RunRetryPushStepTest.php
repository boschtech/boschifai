<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\PushAndOpenPrJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression coverage for a real bug caught live: RunController::retryStep()'s dispatch match
 * and runningStateFor() helper had no case for the 'push' step key at all — a Failed run whose
 * failed_step was 'push' hit the `default` branches in both (422 "cannot be retried directly",
 * and RunState::Failed respectively), so the "Retry step" button never actually worked for a
 * push failure even once a RunStep row existed for it to resolve `failed_step_id` to.
 */
class RunRetryPushStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_a_failed_push_step_dispatches_push_and_open_pr_job(): void
    {
        Queue::fake([PushAndOpenPrJob::class]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'generated_file_path' => 'tests/Feature/XTest.php',
            'state' => RunState::Failed,
            'failed_step' => 'push',
        ]);

        $step = RunStep::create([
            'run_id' => $run->id, 'key' => 'push',
            'status' => RunStepStatus::Failed, 'started_at' => now(), 'finished_at' => now(),
            'error_message' => 'git checkout failed',
        ]);

        $this->postJson("/api/runs/{$run->id}/steps/{$step->id}/retry")->assertOk();

        $run->refresh();
        $this->assertSame(RunState::Pushing, $run->state);
        $this->assertNull($run->failed_step);
        Queue::assertPushed(PushAndOpenPrJob::class);
    }
}
