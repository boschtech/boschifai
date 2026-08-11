<?php

namespace Tests\Feature\Api;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_run_removes_it_and_its_related_records(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => RunState::GapAnalysisReady,
        ]);

        $step = $run->steps()->create(['key' => 'gap_analysis', 'status' => 'succeeded']);
        RunArtifact::makeFromContent($run->id, ArtifactKind::TestabilityReview, '.boschifai/x.md', 'content')->save();

        $this->deleteJson("/api/runs/{$run->id}")->assertNoContent();

        $this->assertDatabaseMissing('runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('run_steps', ['id' => $step->id]);
        $this->assertDatabaseCount('run_artifacts', 0);
    }

    public function test_deleting_an_unknown_run_returns_404(): void
    {
        $this->deleteJson('/api/runs/'.\Illuminate\Support\Str::uuid())->assertNotFound();
    }

    public function test_deleting_a_draft_run_with_no_worktree_still_succeeds(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => RunState::Draft,
        ]);

        $this->deleteJson("/api/runs/{$run->id}")->assertNoContent();
        $this->assertDatabaseMissing('runs', ['id' => $run->id]);
    }
}
