<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalGate;
use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideGapAnalysisRequest;
use App\Http\Requests\DecidePushRequest;
use App\Http\Resources\RunResource;
use App\Jobs\PushAndOpenPrJob;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\Run;

/**
 * The two mandatory human-approval gates (plan §2, §6). Every decision is recorded as a
 * HumanApproval row hashed against the artifact set at that moment — a later mismatch (e.g. a
 * retry regenerating an artifact after approval) invalidates the approval rather than silently
 * pushing stale-approved content, the audit-defensibility mechanism this needs.
 */
class RunApprovalController extends Controller
{
    public function gapAnalysis(DecideGapAnalysisRequest $request, Run $run)
    {
        abort_unless($run->state === RunState::GapAnalysisReady, 422, 'Run is not awaiting the gap-analysis gate.');

        $decision = $request->validated('decision');

        // Coverage mode's Gate 1 covers the codebase-analysis + test-design artifacts instead
        // of a testability review + test plan (see RunGapAnalysisJob/RunTestPlanJob) — the
        // approval-hash mechanism itself (invalidate the approval if the underlying content
        // ever changes) is identical either way.
        $isCoverage = $run->run_type === RunType::Coverage;
        $first = $run->artifactOfKind(($isCoverage ? ArtifactKind::CodebaseKnowledgeBase : ArtifactKind::TestabilityReview)->value);
        $second = $isCoverage ? $run->artifactOfKind(ArtifactKind::TestCasesMarkdown->value) : $run->artifactOfKind(ArtifactKind::TestPlan->value);
        $hash = hash('sha256', ($first?->content ?? '').($second?->content ?? ''));

        $run->approvals()->create([
            'gate' => ApprovalGate::GapAnalysis,
            'decision' => $decision,
            'approved_by' => $request->user()?->email ?? $request->ip(),
            'comment' => $request->validated('comment'),
            'approved_artifact_hash' => $hash,
            'decided_at' => now(),
        ]);

        if ($decision === 'approved') {
            $run->update(['state' => RunState::GenerationRunning]);

            // Coverage mode's test_plan slot already produced the test cases directly (plan's
            // state-reuse mapping) — regenerating them via RunTestCaseGenerationJob would be
            // redundant, so code generation is dispatched straight away.
            if ($isCoverage) {
                RunCodeGenerationJob::dispatch($run->id);
            } else {
                RunTestCaseGenerationJob::dispatch($run->id);
            }
        } else {
            // Both "rejected" and "changes_requested" land here as one terminal state — see
            // Judgment call J5 in the plan: starting a fresh Run with the edited requirement
            // is a manual "New requirement" submission for MVP, not an automatic fork, since
            // there is no edited text to carry forward at the moment this decision is made.
            $run->update([
                'state' => RunState::GapAnalysisRejected,
                'rejection_comment' => $request->validated('comment'),
            ]);
        }

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    public function push(DecidePushRequest $request, Run $run)
    {
        abort_unless($run->state === RunState::LocalExecutionComplete, 422, 'Run is not awaiting the push gate.');

        $decision = $request->validated('decision');

        $generatedCode = $run->artifactOfKind(ArtifactKind::GeneratedTest->value);
        $hash = hash('sha256', $generatedCode?->content ?? '');

        $run->approvals()->create([
            'gate' => ApprovalGate::PrePush,
            'decision' => $decision,
            'approved_by' => $request->user()?->email ?? $request->ip(),
            'comment' => $request->validated('comment'),
            'approved_artifact_hash' => $hash,
            'decided_at' => now(),
        ]);

        if ($decision === 'approved') {
            $run->update(['state' => RunState::Pushing]);
            PushAndOpenPrJob::dispatch($run->id);
        } else {
            $run->update([
                'state' => RunState::PushRejected,
                'rejection_comment' => $request->validated('comment'),
            ]);
        }

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }
}
