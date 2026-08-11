<?php

namespace Tests\Feature\Services;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Pipeline\CancellationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A DB-mediated check, not an in-memory one — deliberately uses a real database (RefreshDatabase)
 * rather than mocks, since the whole point of CancellationChecker is that it must see a flag set
 * by a *different* process (the HTTP request handling "Stop") than the one running the job.
 */
class CancellationCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => RunState::GapAnalysisRunning,
        ]);
    }

    public function test_is_requested_is_false_until_the_column_is_stamped(): void
    {
        $run = $this->makeRun();
        $checker = new CancellationChecker();

        $this->assertFalse($checker->isRequested($run->id));

        $run->update(['cancel_requested_at' => now()]);

        $this->assertTrue($checker->isRequested($run->id));
    }

    public function test_is_requested_reads_fresh_from_the_database_not_a_stale_in_memory_model(): void
    {
        $run = $this->makeRun();
        $checker = new CancellationChecker();

        // Simulate the real scenario this class exists for: a separate process (the HTTP
        // request handling "Stop") updates the row directly, without going through this
        // in-memory $run instance at all.
        Run::whereKey($run->id)->update(['cancel_requested_at' => now()]);

        $this->assertTrue($checker->isRequested($run->id));
    }

    public function test_mark_cancelled_sets_run_state_and_creates_a_cancelled_step_when_none_existed(): void
    {
        $run = $this->makeRun();
        $checker = new CancellationChecker();

        $checker->markCancelled($run, 'gap_analysis', 'Cancelled by user before this step started.');

        $run->refresh();
        $this->assertSame(RunState::Cancelled, $run->state);

        $step = $run->stepByKey('gap_analysis');
        $this->assertNotNull($step);
        $this->assertSame(RunStepStatus::Cancelled, $step->status);
        $this->assertNotNull($step->started_at);
        $this->assertNotNull($step->finished_at);
        $this->assertSame('Cancelled by user before this step started.', $step->error_message);
    }

    public function test_mark_cancelled_preserves_the_real_started_at_for_a_step_already_in_flight(): void
    {
        $run = $this->makeRun();
        $checker = new CancellationChecker();

        $realStart = now()->subMinutes(3);
        $run->steps()->create([
            'key' => 'gap_analysis',
            'status' => RunStepStatus::Running,
            'started_at' => $realStart,
        ]);

        $checker->markCancelled($run, 'gap_analysis', 'Cancelled by user.');

        $step = $run->stepByKey('gap_analysis')->fresh();
        // The regression this guards against: an earlier implementation unconditionally reset
        // started_at to now() on every call, silently destroying the real start time for a step
        // that was already mid-flight when cancelled. Compared at second precision — the
        // `started_at` column truncates sub-second precision on write, so an exact Carbon
        // equalTo() against the original (microsecond-precision) value would fail even when
        // nothing is wrong.
        $this->assertTrue($realStart->startOfSecond()->equalTo($step->started_at));
        $this->assertSame(RunStepStatus::Cancelled, $step->status);
    }
}
