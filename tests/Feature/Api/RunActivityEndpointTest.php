<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Added in direct response to a real user report: a submitted run sat in `draft` state with no
 * queue worker running to pick it up, and the UI gave zero feedback — indistinguishable from a
 * broken page. This endpoint is what the run detail page now polls to show real progress.
 */
class RunActivityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(RunState $state): Run
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => $state,
        ]);
    }

    public function test_a_draft_run_with_no_running_step_reports_queued_and_names_the_likely_cause(): void
    {
        $run = $this->makeRun(RunState::Draft);

        $this->getJson("/api/runs/{$run->id}/activity")
            ->assertOk()
            ->assertJson(['phase' => 'queued', 'step_key' => null])
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'queue:work'));
    }

    public function test_a_terminal_state_with_no_running_step_reports_idle(): void
    {
        $run = $this->makeRun(RunState::ReportReady);

        $this->getJson("/api/runs/{$run->id}/activity")
            ->assertOk()
            ->assertJson(['phase' => 'idle', 'message' => null]);
    }

    public function test_a_running_non_claude_step_reports_a_descriptive_message_with_no_log_lines(): void
    {
        $run = $this->makeRun(RunState::LocalExecutionRunning);
        $run->steps()->create([
            'key' => 'local_execution',
            'status' => RunStepStatus::Running,
            'started_at' => now()->subSeconds(12),
        ]);

        $response = $this->getJson("/api/runs/{$run->id}/activity")->assertOk();

        $response->assertJson(['phase' => 'running', 'step_key' => 'local_execution']);
        $this->assertStringContainsString('composer install', $response->json('message'));
        $this->assertSame([], $response->json('log_lines'));
        $this->assertGreaterThanOrEqual(12, $response->json('elapsed_seconds'));
    }

    public function test_a_running_local_execution_step_tails_its_real_output_log(): void
    {
        // logPathFor() lives on the shared jobs volume that only the `test-runner` sidecar and
        // `worker` can write to (`app`, which serves this endpoint, mounts it read-only — see
        // docker-compose.yml's `test-runner` service comment) — pointed at a writable temp dir
        // here so this test doesn't depend on which container happens to run the suite.
        $jobsDir = sys_get_temp_dir().'/boschifai-jobs-test-'.uniqid();
        config(['boschifai.test_runner.jobs_path' => $jobsDir]);

        $run = $this->makeRun(RunState::LocalExecutionRunning);
        $run->steps()->create([
            'key' => 'local_execution',
            'status' => RunStepStatus::Running,
            'started_at' => now()->subSeconds(20),
        ]);

        $logPath = app(\App\Services\TestExecution\LocalTestRunner::class)->logPathFor($run->id);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($logPath));
        \Illuminate\Support\Facades\File::put($logPath, "Installing dependencies...\n\nRunning phpunit...\nPASS  CustomFieldControllerTest\n");

        $response = $this->getJson("/api/runs/{$run->id}/activity")->assertOk();

        $response->assertJson(['phase' => 'running', 'step_key' => 'local_execution', 'message' => null]);
        $this->assertSame(
            ["Installing dependencies...\nRunning phpunit...\nPASS  CustomFieldControllerTest"],
            $response->json('log_lines')
        );

        \Illuminate\Support\Facades\File::deleteDirectory($jobsDir);
    }

    public function test_a_running_claude_step_tails_its_real_transcript(): void
    {
        $run = $this->makeRun(RunState::GapAnalysisRunning);
        $step = $run->steps()->create([
            'key' => 'gap_analysis',
            'status' => RunStepStatus::Running,
            'started_at' => now()->subSeconds(5),
        ]);

        $transcriptPath = storage_path('app/boschifai-runs/test-transcript.jsonl');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($transcriptPath));
        \Illuminate\Support\Facades\File::put($transcriptPath, implode("\n", [
            json_encode(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => 'Reading the requirement file.'],
            ]]]),
            json_encode(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '.boschifai/requirement_x.md']],
            ]]]),
        ]));

        $step->claudeInvocations()->create([
            'command' => '/boschifai-review',
            'prompt_text' => 'x',
            'raw_transcript_path' => $transcriptPath,
            'exit_code' => null,
            'total_cost_usd' => 0.05,
        ]);

        $response = $this->getJson("/api/runs/{$run->id}/activity")->assertOk();

        $response->assertJson(['phase' => 'claude_running', 'step_key' => 'gap_analysis', 'cost_so_far_usd' => 0.05]);
        $this->assertSame([
            'Reading the requirement file.',
            '→ Read: .boschifai/requirement_x.md',
        ], $response->json('log_lines'));

        \Illuminate\Support\Facades\File::delete($transcriptPath);
    }
}
