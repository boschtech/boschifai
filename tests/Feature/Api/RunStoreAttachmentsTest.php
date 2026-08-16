<?php

namespace Tests\Feature\Api;

use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Attach supporting files from your machine" on the run-create form — covers upload storage
 * and the attachment_filenames manifest RunGapAnalysisJob later reads to copy them into the
 * checkout (see RunGapAnalysisAttachmentsTest for that half).
 */
class RunStoreAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => sys_get_temp_dir(), 'base_branch' => 'main',
        ]);
    }

    public function test_uploaded_attachments_are_stored_and_recorded_on_the_run(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        Storage::fake('local');
        $repo = $this->makeRepo();

        $response = $this->post('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'attachments' => [
                UploadedFile::fake()->create('spec.pdf', 100),
                UploadedFile::fake()->image('screenshot.png'),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $run = Run::find($response->json('data.id'));
        $this->assertCount(2, $run->attachment_filenames);
        $this->assertStringEndsWith('_spec.pdf', $run->attachment_filenames[0]);
        $this->assertStringEndsWith('_screenshot.png', $run->attachment_filenames[1]);

        foreach ($run->attachment_filenames as $storedName) {
            Storage::disk('local')->assertExists("boschifai-runs/{$run->id}/attachments/{$storedName}");
        }

        Queue::assertPushed(RunGapAnalysisJob::class);
    }

    public function test_more_than_five_attachments_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $files = array_map(fn ($i) => UploadedFile::fake()->create("file{$i}.txt", 10), range(1, 6));

        $this->post('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'attachments' => $files,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');
    }

    public function test_an_oversized_attachment_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $this->post('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'attachments' => [UploadedFile::fake()->create('huge.zip', 10241)], // just over 10MB
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');
    }

    public function test_submitting_with_no_attachments_still_works_exactly_as_before(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertOk();

        $run = Run::find($response->json('data.id'));
        $this->assertSame([], $run->attachment_filenames);
    }
}
