<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\Confidence\ConfidenceScoreCalculator;
use App\Services\Github\CiPollingService;
use App\Services\Pipeline\CancellationChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Self-requeuing poll of RAMS's existing sonar-scan.yml check-run — no workflow files are
 * touched (plan §7). Bounded by boschifai.github.ci_poll_timeout_seconds so a check that never
 * appears (e.g. a misconfigured branch trigger) doesn't poll forever.
 */
class PollCiStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $runId, public int $elapsedSeconds = 0)
    {
    }

    public function handle(CiPollingService $ci, ConfidenceScoreCalculator $confidence, CancellationChecker $cancellation): void
    {
        $run = Run::findOrFail($this->runId);

        // This is the case cancellation matters most for: without this guard, a run left
        // "cancelled" mid-poll would keep re-dispatching itself every
        // boschifai.github.ci_poll_interval_seconds for up to 30 minutes regardless, since
        // nothing else in this job's own loop would ever notice. One check per poll iteration
        // (already throttled by the poll interval itself) is more than sufficient — no need for
        // the sub-second polling pattern the Claude/Docker invocations use, since there's no
        // single long-running process here to interrupt, just a self-requeue to stop.
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::CiPoll->value, 'Cancelled by user while waiting on CI.');

            return;
        }

        $result = $ci->poll($run);

        $interval = config('boschifai.github.ci_poll_interval_seconds');
        $timeout = config('boschifai.github.ci_poll_timeout_seconds');

        if ($result === null || $result['conclusion'] === null) {
            if ($this->elapsedSeconds >= $timeout) {
                $run->update([
                    'state' => RunState::Failed,
                    'failed_step' => RunStepKey::CiPoll->value,
                    'error_message' => "CI check did not conclude within {$timeout}s.",
                ]);

                return;
            }

            self::dispatch($run->id, $this->elapsedSeconds + $interval)->delay(now()->addSeconds($interval));

            return;
        }

        $run->ciCheckResult()->updateOrCreate(
            ['run_id' => $run->id],
            [
                'workflow_name' => 'Sonar Scan',
                'check_run_id' => $result['check_run_id'],
                'conclusion' => $result['conclusion'],
                'polled_at' => now(),
            ]
        );

        $run->refresh();
        $breakdown = $confidence->calculate($run);

        $run->update([
            'state' => RunState::ReportReady,
            'confidence_score' => $breakdown['composite'],
            'confidence_breakdown' => $breakdown,
        ]);
    }
}
