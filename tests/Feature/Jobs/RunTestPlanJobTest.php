<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunType;
use App\Jobs\RunTestPlanJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Regression coverage for a real incident caught live: coverage mode's test-design step
 * recommended an already-existing test file as its own RECOMMENDED_TARGET_FILE (rather than the
 * application source it covers) on a re-run against a controller that already had a generated
 * test in the shared checkout. /boschifai-gen-component then EDITED that already-untracked file
 * in place instead of creating a new one — which never looks "new" to the git-status diff two
 * steps later, surfacing as a confusing "No generated test file was detected" failure at
 * code_generation instead of a clear one here, right where the bad value originated.
 */
class RunTestPlanJobTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-test-plan-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeCoverageRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => RunType::Coverage,
            'requirement_text' => 'Increase coverage for the search suggestions controller.',
            // Coverage mode doesn't know the target file up front (that's this job's own job to
            // resolve) — '' is the real placeholder StoreRunRequest/RunController::store() write
            // for a fresh coverage submission, matching the column's NOT NULL constraint.
            'target_file_path' => '',
            'state' => RunState::GapAnalysisRunning,
        ]);
    }

    private function bindRecommendedTargetFile(?string $path): void
    {
        $parser = new class extends ClaudeTranscriptParser
        {
            public ?string $fixedValue = null;

            public function extractRecommendedTargetFile(string $transcriptPath): ?string
            {
                return $this->fixedValue;
            }
        };
        $parser->fixedValue = $path;

        $this->app->instance(ClaudeTranscriptParser::class, $parser);
    }

    public function test_a_recommendation_that_looks_like_a_test_file_fails_fast_with_a_clear_message(): void
    {
        Process::fake(fn () => Process::result());
        $this->bindRecommendedTargetFile('tests/Feature/AccountSearchSuggestionsControllerTest.php');

        $run = $this->makeCoverageRun();

        app()->call([new RunTestPlanJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('test_plan', $run->failed_step);
        $this->assertStringContainsString('looks like a test file itself', $run->error_message);
        // The bad value must never reach target_file_path — code_generation would just repeat
        // the exact same failure on whatever bogus path was accepted here.
        $this->assertSame('', $run->target_file_path);
    }

    public function test_a_recommendation_that_is_real_application_source_proceeds_to_gate_1(): void
    {
        Process::fake(fn () => Process::result());
        $this->bindRecommendedTargetFile('app/Http/Controllers/Accounting/AccountSearchSuggestionsController.php');

        $run = $this->makeCoverageRun();

        app()->call([new RunTestPlanJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::GapAnalysisReady, $run->state);
        $this->assertSame('app/Http/Controllers/Accounting/AccountSearchSuggestionsController.php', $run->target_file_path);
    }
}
