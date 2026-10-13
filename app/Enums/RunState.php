<?php

namespace App\Enums;

/**
 * See plan §2 for the full state diagram. Only gap_analysis_ready → generation_running and
 * local_execution_complete → pushing require a human decision (HumanApproval) — every other
 * transition is system-driven.
 */
enum RunState: string
{
    case Draft = 'draft';

    case GapAnalysisRunning = 'gap_analysis_running';
    case GapAnalysisReady = 'gap_analysis_ready';
    case GapAnalysisRejected = 'gap_analysis_rejected';
    // "Request changes" at Gate 1 — not a rejection: this run is closed and a linked follow-up
    // run (previous_run_id → this one) reruns Gate 1 with the reviewer's feedback. Terminal so
    // the follow-up can take over the repo's shared checkout (see RunController::store's guard).
    case GapAnalysisChangesRequested = 'gap_analysis_changes_requested';

    case GenerationRunning = 'generation_running';
    case GenerationBlocked = 'generation_blocked';

    case LocalExecutionRunning = 'local_execution_running';
    case LocalExecutionComplete = 'local_execution_complete';

    case PushRejected = 'push_rejected';
    case Pushing = 'pushing';
    case CiPending = 'ci_pending';
    case CiComplete = 'ci_complete';
    case ReportReady = 'report_ready';

    // Standalone Actions (build_skills/build_knowledge_base run types) — a single step, no
    // gate, no push: see RunStandaloneActionJob. Shared across both run types rather than
    // giving each its own running/complete pair, same as Failed/Cancelled being shared already.
    case StandaloneRunning = 'standalone_running';
    case StandaloneComplete = 'standalone_complete';

    // The workspace couldn't cover the next Claude step's credit hold (billing.enforce). Not a
    // failure: runs.awaiting_credits_step says which step, and resuming once credits are added
    // starts it (RunController::resume).
    case AwaitingCredits = 'awaiting_credits';

    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::GapAnalysisRejected,
            self::GapAnalysisChangesRequested,
            self::GenerationBlocked,
            self::PushRejected,
            self::ReportReady,
            self::StandaloneComplete,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function awaitsHumanGate(): ?string
    {
        return match ($this) {
            self::GapAnalysisReady => 'gap_analysis',
            self::LocalExecutionComplete => 'pre_push',
            default => null,
        };
    }
}
