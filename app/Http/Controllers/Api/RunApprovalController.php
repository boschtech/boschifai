<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalGate;
use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideGapAnalysisRequest;
use App\Http\Requests\DecidePushRequest;
use App\Http\Resources\RunResource;
use App\Jobs\PushAndOpenPrJob;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\Run;
use App\Models\User;
use App\Services\Billing\CreditGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * The two mandatory human-approval gates (plan §2, §6). Every decision is recorded as a
 * HumanApproval row hashed against the artifact set at that moment — a later mismatch (e.g. a
 * retry regenerating an artifact after approval) invalidates the approval rather than silently
 * pushing stale-approved content, the audit-defensibility mechanism this needs.
 */
class RunApprovalController extends Controller
{
    public function gapAnalysis(DecideGapAnalysisRequest $request, Run $run, CreditGate $credits)
    {
        abort_unless($run->state === RunState::GapAnalysisReady, 422, 'Run is not awaiting the gap-analysis gate.');

        $decision = $request->validated('decision');
        // Checked before the decision is recorded, so a refused click leaves the gate open.
        $nextStep = match ($decision) {
            'approved' => $run->run_type === RunType::Coverage ? RunStepKey::CodeGeneration : RunStepKey::TestCaseGeneration,
            'changes_requested' => RunStepKey::GapAnalysis,
            default => null,
        };
        if ($nextStep) {
            $credits->assertCanStart($run->workspace, $nextStep->value);
        }

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
            'approved_by' => $request->user()->email,
            'approved_by_user_id' => $request->user()->id,
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
        } elseif ($decision === 'changes_requested') {
            $this->startRevisedRun($run, $request->validated('comment'), $request->user());
        } else {
            $run->update([
                'state' => RunState::GapAnalysisRejected,
                'rejection_comment' => $request->validated('comment'),
            ]);
        }

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * "Request changes" reruns Gate 1 with the reviewer's feedback instead of ending the run.
     * Done as a new linked Run (previous_run_id, same lineage as the Edit flow — plan J5: runs
     * are immutable) rather than re-invoking the steps on this one, for two reasons: the
     * artifacts this approval row just hashed stay exactly as reviewed, and artifact discovery
     * (GitStatusDiffCollector) only notices NEWLY untracked files — rewriting this run's
     * already-untracked testability_review_<id>.md in place would never be picked up, whereas
     * the new run's own id gives every output file a fresh name.
     *
     * The feedback is appended to the requirement text itself, so every Gate 1 step (review
     * and test plan / codebase analysis and test design) reads it from the same input file.
     */
    private function startRevisedRun(Run $run, string $comment, User $requestedBy): Run
    {
        $revised = DB::transaction(function () use ($run, $comment, $requestedBy) {
            // Closed first so the repo's one-active-run slot (RunController::store) is free.
            $run->update([
                'state' => RunState::GapAnalysisChangesRequested,
                'rejection_comment' => $comment,
            ]);

            return Run::create([
                'repo_config_id' => $run->repo_config_id,
                'run_type' => $run->run_type,
                'requirement_text' => $run->requirement_text."\n\n---\n\n## Reviewer feedback (changes requested at Gate 1)\n\n"
                    .$comment
                    ."\n\nRevise the analysis and plan to address this feedback.",
                'target_file_path' => $run->target_file_path,
                'attachment_filenames' => $run->attachment_filenames ?? [],
                'previous_run_id' => $run->id,
                'state' => RunState::Draft,
                'created_by' => $requestedBy->email,
                'created_by_user_id' => $requestedBy->id,
            ]);
        });

        // Attachments live under the run's own id (RunController::storeAttachments), so the
        // follow-up needs its own copy for RunGapAnalysisJob to find them.
        $sourceDir = storage_path("app/boschifai-runs/{$run->id}/attachments");
        if (File::isDirectory($sourceDir)) {
            File::copyDirectory($sourceDir, storage_path("app/boschifai-runs/{$revised->id}/attachments"));
        }

        RunGapAnalysisJob::dispatch($revised->id);

        return $revised;
    }

    public function push(DecidePushRequest $request, Run $run, CreditGate $credits)
    {
        abort_unless($run->state === RunState::LocalExecutionComplete, 422, 'Run is not awaiting the push gate.');

        $decision = $request->validated('decision');
        if ($decision === 'approved') {
            $credits->assertCanStart($run->workspace, RunStepKey::Push->value);
        }

        $generatedCode = $run->artifactOfKind(ArtifactKind::GeneratedTest->value);
        $hash = hash('sha256', $generatedCode?->content ?? '');

        $run->approvals()->create([
            'gate' => ApprovalGate::PrePush,
            'decision' => $decision,
            'approved_by' => $request->user()->email,
            'approved_by_user_id' => $request->user()->id,
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
