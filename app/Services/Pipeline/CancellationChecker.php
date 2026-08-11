<?php

namespace App\Services\Pipeline;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;

/**
 * Cooperative cancellation: `RunController::cancel()` (a request in the `app` container) has no
 * way to directly signal a process that might be executing inside the `worker` container — they
 * don't share a PID namespace, and Docker-outside-of-Docker only exists for spinning up sibling
 * `rams-app` containers, not for reaching into `worker` itself. So cancellation is entirely
 * DB-mediated: the HTTP request just stamps `runs.cancel_requested_at`, and whatever job is
 * currently executing polls that column itself (via `isRequested()`, a fresh single-column
 * query — cheap even polled every second) and stops its own in-flight process. No cross-process
 * signaling is needed because the check and the process being cancelled always run inside the
 * same PHP worker.
 */
class CancellationChecker
{
    public function isRequested(string $runId): bool
    {
        // Deliberately a fresh query, not $run->cancel_requested_at on an in-memory model —
        // the cancellation request arrives via a completely separate HTTP request/process, so
        // an already-loaded Run instance would never see it.
        return Run::whereKey($runId)->value('cancel_requested_at') !== null;
    }

    /**
     * Marks both the given step and the run itself as cancelled. Idempotent — safe to call from
     * multiple guard points (top-of-job, pre-invocation, mid-invocation) without double-booking.
     *
     * Uses `firstOrNew` rather than `updateOrCreate`, specifically to avoid clobbering
     * `started_at`: a step cancelled mid-flight already has a real start time set by whatever
     * created it (StepExecutionService::run(), RunLocalTestExecutionJob, etc.) — only a step
     * that never got that far (a pre-check firing before any RunStep row exists) should get
     * `started_at` backfilled here.
     */
    public function markCancelled(Run $run, string $stepKey, string $message = 'Cancelled by user.'): void
    {
        $step = RunStep::firstOrNew(['run_id' => $run->id, 'key' => $stepKey]);

        if (! $step->exists) {
            $step->started_at = now();
        }

        $step->status = RunStepStatus::Cancelled;
        $step->finished_at = now();
        $step->error_message = $message;
        $step->save();

        $run->update(['state' => RunState::Cancelled]);
    }
}
