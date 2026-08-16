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

    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::GapAnalysisRejected,
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
