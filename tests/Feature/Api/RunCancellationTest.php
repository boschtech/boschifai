<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunCancellationTest extends TestCase
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

    /** @dataProvider cancellableStatesProvider */
    public function test_cancel_stamps_cancel_requested_at_for_cancellable_states(RunState $state): void
    {
        $run = $this->makeRun($state);

        $response = $this->postJson("/api/runs/{$run->id}/cancel")->assertOk();

        $this->assertNotNull($response->json('data.cancel_requested_at'));
        $this->assertNotNull($run->fresh()->cancel_requested_at);
        // The endpoint only stamps the flag — it does NOT flip the state itself, since the
        // actual job (possibly running in a different container) is what notices and
        // transitions to `cancelled` once it polls the flag.
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

    /** @dataProvider noncancellableStatesProvider */
    public function test_cancel_is_rejected_for_states_with_no_in_flight_work(RunState $state): void
    {
        $run = $this->makeRun($state);

        $this->postJson("/api/runs/{$run->id}/cancel")->assertStatus(422);

        $this->assertNull($run->fresh()->cancel_requested_at);
    }

    public static function noncancellableStatesProvider(): array
    {
        return [
            'awaiting gate 1' => [RunState::GapAnalysisReady],
            'awaiting gate 2' => [RunState::LocalExecutionComplete],
            'already failed' => [RunState::Failed],
            'already cancelled' => [RunState::Cancelled],
            'already succeeded' => [RunState::ReportReady],
            'rejected' => [RunState::GapAnalysisRejected],
        ];
    }
}
