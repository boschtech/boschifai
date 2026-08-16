<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunTestPlanJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Supporting files uploaded from the user's own machine (RunController::store) must actually
 * reach Claude — this covers the full path: stored upload -> copied into the checkout's
 * .boschifai/attachments/ -> referenced in the /boschifai-review prompt's own -p argument.
 */
class RunGapAnalysisAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-attachments-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    public function test_a_stored_attachment_is_copied_into_the_checkout_and_referenced_in_the_prompt(): void
    {
        Queue::fake([RunTestPlanJob::class]);
        Process::fake(fn () => Process::result());

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'attachment_filenames' => ['ab12cd34_spec.pdf'],
            'state' => RunState::Draft,
        ]);

        $storedDir = storage_path("app/boschifai-runs/{$run->id}/attachments");
        File::ensureDirectoryExists($storedDir);
        File::put($storedDir.'/ab12cd34_spec.pdf', 'fake pdf bytes');

        app()->call([new RunGapAnalysisJob($run->id), 'handle']);

        $copiedPath = $this->checkoutPath.'/.boschifai/attachments/ab12cd34_spec.pdf';
        $this->assertFileExists($copiedPath);
        $this->assertSame('fake pdf bytes', File::get($copiedPath));

        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command)
                && ($command[0] ?? null) === 'claude'
                && ($command[1] ?? null) === '-p'
                && str_contains($command[2] ?? '', '.boschifai/attachments/ab12cd34_spec.pdf')
                && str_contains($command[2] ?? '', 'supporting reference files');
        });

        Queue::assertPushed(RunTestPlanJob::class);

        File::deleteDirectory(storage_path("app/boschifai-runs/{$run->id}"));
    }

    public function test_a_missing_stored_file_is_skipped_rather_than_failing_the_run(): void
    {
        Queue::fake([RunTestPlanJob::class]);
        Process::fake(fn () => Process::result());

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'attachment_filenames' => ['never-actually-stored.png'],
            'state' => RunState::Draft,
        ]);

        app()->call([new RunGapAnalysisJob($run->id), 'handle']);

        $run->refresh();
        $this->assertNotSame(RunState::Failed, $run->state);
        Queue::assertPushed(RunTestPlanJob::class);
    }
}
