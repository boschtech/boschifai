<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Jobs\RunStandaloneActionJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Covers RunStandaloneActionJob — the single-step, no-gate, no-push job behind both "Standalone
 * Actions" (SidebarNav): build_skills / build_knowledge_base. Unlike the gated pipeline jobs,
 * there is deliberately no chained job dispatch on success — the run just reaches
 * standalone_complete with its one artifact attached.
 */
class RunStandaloneActionJobTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-standalone-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(RunType $runType): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => $runType,
            'requirement_text' => 'Build a project skill for this repository.',
            'target_file_path' => '',
            'state' => RunState::Draft,
        ]);
    }

    /**
     * Fakes `claude` to write the real target artifact file to disk (the actual side effect a
     * real invocation would have) and reports it via `git status --porcelain --untracked-files=all`
     * from that point on — the exact mechanism RunStandaloneActionJob relies on
     * (GitStatusDiffCollector), not transcript parsing. Everything else (boschifai init, etc.)
     * gets a plain empty success.
     */
    private function fakeClaudeWriting(string $relativeFilename, string $content): void
    {
        $claudeRan = false;

        Process::fake(function ($process) use (&$claudeRan, $relativeFilename, $content) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                File::put($this->checkoutPath.'/'.$relativeFilename, $content);
                $claudeRan = true;

                return Process::result(output: json_encode([
                    'type' => 'assistant',
                    'message' => ['content' => [['type' => 'text', 'text' => 'Wrote the file.']]],
                ])."\n");
            }

            if (str_contains($command, 'git status --porcelain --untracked-files=all')) {
                return Process::result(output: $claudeRan ? "?? {$relativeFilename}\n" : '');
            }

            return Process::result();
        });
    }

    public function test_build_knowledge_base_writes_the_artifact_and_completes(): void
    {
        $run = $this->makeRun(RunType::BuildKnowledgeBase);
        $filename = "codebase_knowledge_base_{$run->id}.md";

        $this->fakeClaudeWriting($filename, "| **Codebase Understanding Score** | 85% |\n\n## Tech Stack\nLaravel.\n");

        app()->call([new RunStandaloneActionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::StandaloneComplete, $run->state);
        $this->assertSame(85, $run->testability_score);

        $step = $run->steps()->where('key', 'build_knowledge_base')->first();
        $this->assertNotNull($step);
        $this->assertSame('succeeded', $step->status->value);

        $artifact = $run->artifacts()->where('kind', 'codebase_knowledge_base')->first();
        $this->assertNotNull($artifact);
        $this->assertStringContainsString('Laravel', $artifact->content);
    }

    public function test_build_skills_writes_the_artifact_and_completes(): void
    {
        $run = $this->makeRun(RunType::BuildSkills);
        $filename = "project_skill_{$run->id}.md";

        $this->fakeClaudeWriting($filename, "| **Codebase Understanding Score** | 72% |\n\n---\nname: boschifai-acme-backend\n---\n");

        app()->call([new RunStandaloneActionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::StandaloneComplete, $run->state);
        $this->assertSame(72, $run->testability_score);

        $artifact = $run->artifacts()->where('kind', 'project_skill')->first();
        $this->assertNotNull($artifact);
        $this->assertStringContainsString('boschifai-acme-backend', $artifact->content);
    }

    public function test_a_failed_claude_invocation_marks_the_run_failed_with_the_right_step(): void
    {
        $run = $this->makeRun(RunType::BuildKnowledgeBase);

        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return Process::result(errorOutput: 'boom', exitCode: 1);
            }

            return Process::result();
        });

        app()->call([new RunStandaloneActionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('build_knowledge_base', $run->failed_step);
        $this->assertStringContainsString('boom', $run->error_message);
    }

    public function test_no_artifact_detected_still_completes_with_a_null_score_rather_than_erroring(): void
    {
        $run = $this->makeRun(RunType::BuildSkills);

        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return Process::result(output: json_encode([
                    'type' => 'assistant',
                    'message' => ['content' => [['type' => 'text', 'text' => 'I could not write anything.']]],
                ])."\n");
            }

            return Process::result();
        });

        app()->call([new RunStandaloneActionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::StandaloneComplete, $run->state);
        $this->assertNull($run->testability_score);
    }

    /**
     * Regression coverage for the same real bug class fixed twice already this session
     * (RunGapAnalysisJob/PushAndOpenPrJob): a failure inside workspace->prepare() — before
     * StepExecutionService ever runs — must still leave a RunStep row behind, or the "Retry
     * step" button's route-model-bound `{step}` has nothing to resolve `failed_step_id` to and
     * 404s on `/steps/null/retry`.
     */
    public function test_a_workspace_prepare_failure_marks_the_run_and_step_failed(): void
    {
        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_contains($command, 'boschifai init')) {
                return Process::result(exitCode: 1, errorOutput: 'Read-only file system (os error 30)');
            }

            return Process::result();
        });

        $run = $this->makeRun(RunType::BuildKnowledgeBase);

        app()->call([new RunStandaloneActionJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('build_knowledge_base', $run->failed_step);
        $this->assertStringContainsString('Read-only file system', $run->error_message);

        $step = $run->steps()->where('key', 'build_knowledge_base')->first();
        $this->assertNotNull($step, 'Expected a RunStep row to exist even though prepare() failed before StepExecutionService ran.');
        $this->assertSame(RunStepStatus::Failed, $step->status);
    }
}
