<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);
    }

    private function makeRun(RepoConfig $repo, RunState $state): Run
    {
        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => $state,
        ]);
    }

    /** @dataProvider cancellableStatesProvider */
    public function test_archiving_a_cancellable_state_also_requests_cancellation(RunState $state): void
    {
        $run = $this->makeRun($this->makeRepo(), $state);

        $response = $this->postJson("/api/runs/{$run->id}/archive")->assertOk();

        $this->assertNotNull($response->json('data.archived_at'));
        $this->assertNotNull($run->fresh()->cancel_requested_at);
        // Archiving doesn't itself flip the state — the in-flight job notices the cancellation
        // flag and transitions on its own, same as a plain cancel().
        $this->assertSame($state, $run->fresh()->state);
    }

    public static function cancellableStatesProvider(): array
    {
        return [
            'draft' => [RunState::Draft],
            'gap analysis running' => [RunState::GapAnalysisRunning],
            'generation running' => [RunState::GenerationRunning],
            'local execution running' => [RunState::LocalExecutionRunning],
            'pushing' => [RunState::Pushing],
            'ci pending' => [RunState::CiPending],
        ];
    }

    /** @dataProvider nonCancellableStatesProvider */
    public function test_archiving_a_non_cancellable_state_does_not_touch_cancel_requested_at(RunState $state): void
    {
        $run = $this->makeRun($this->makeRepo(), $state);

        $this->postJson("/api/runs/{$run->id}/archive")->assertOk();

        $this->assertNull($run->fresh()->cancel_requested_at);
        $this->assertNotNull($run->fresh()->archived_at);
    }

    public static function nonCancellableStatesProvider(): array
    {
        return [
            'awaiting gate 1' => [RunState::GapAnalysisReady],
            'awaiting gate 2' => [RunState::LocalExecutionComplete],
            'already failed' => [RunState::Failed],
            'already succeeded' => [RunState::ReportReady],
        ];
    }

    public function test_archiving_a_non_terminal_run_frees_the_repo_for_a_new_run(): void
    {
        $repo = $this->makeRepo();
        $run = $this->makeRun($repo, RunState::GapAnalysisReady);

        $this->postJson("/api/runs/{$run->id}/archive")->assertOk();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'a new requirement',
            'target_file_path' => 'y.php',
        ]);

        $response->assertOk();
    }

    public function test_store_guard_still_blocks_when_the_conflicting_run_is_not_archived(): void
    {
        $repo = $this->makeRepo();
        $this->makeRun($repo, RunState::GapAnalysisReady);

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'a new requirement',
            'target_file_path' => 'y.php',
        ]);

        $response->assertStatus(422);
    }

    public function test_index_excludes_archived_runs_by_default(): void
    {
        $repo = $this->makeRepo();
        $active = $this->makeRun($repo, RunState::Draft);
        $archived = $this->makeRun($repo, RunState::ReportReady);
        $archived->update(['archived_at' => now()]);

        $response = $this->getJson('/api/runs')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_index_archived_param_shows_only_archived_runs(): void
    {
        $repo = $this->makeRepo();
        $active = $this->makeRun($repo, RunState::Draft);
        $archived = $this->makeRun($repo, RunState::ReportReady);
        $archived->update(['archived_at' => now()]);

        $response = $this->getJson('/api/runs?archived=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($archived->id));
        $this->assertFalse($ids->contains($active->id));
    }

    public function test_unarchive_a_terminal_run_always_succeeds_even_with_another_active_run(): void
    {
        $repo = $this->makeRepo();
        $archived = $this->makeRun($repo, RunState::ReportReady);
        $archived->update(['archived_at' => now()]);
        $this->makeRun($repo, RunState::GapAnalysisRunning);

        $response = $this->postJson("/api/runs/{$archived->id}/unarchive")->assertOk();

        $this->assertNull($response->json('data.archived_at'));
        $this->assertNull($archived->fresh()->archived_at);
    }

    public function test_unarchive_a_non_terminal_run_is_blocked_by_another_active_run_on_the_same_repo(): void
    {
        $repo = $this->makeRepo();
        $archived = $this->makeRun($repo, RunState::GapAnalysisReady);
        $archived->update(['archived_at' => now()]);
        $this->makeRun($repo, RunState::GapAnalysisRunning);

        $this->postJson("/api/runs/{$archived->id}/unarchive")->assertStatus(422);

        $this->assertNotNull($archived->fresh()->archived_at);
    }

    public function test_unarchive_a_non_terminal_run_succeeds_when_no_other_active_run_exists(): void
    {
        $repo = $this->makeRepo();
        $archived = $this->makeRun($repo, RunState::GapAnalysisReady);
        $archived->update(['archived_at' => now()]);

        $this->postJson("/api/runs/{$archived->id}/unarchive")->assertOk();

        $this->assertNull($archived->fresh()->archived_at);
    }

    public function test_unarchive_a_run_that_is_not_archived_is_rejected(): void
    {
        $run = $this->makeRun($this->makeRepo(), RunState::ReportReady);

        $this->postJson("/api/runs/{$run->id}/unarchive")->assertStatus(422);
    }
}
