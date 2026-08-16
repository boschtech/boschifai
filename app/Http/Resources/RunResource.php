<?php

namespace App\Http\Resources;

use App\Enums\ArtifactKind;
use App\Services\Confidence\ConfidenceScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $generatedCode = $this->artifactOfKind(ArtifactKind::GeneratedTest->value)?->content;
        $failedStep = $this->steps->firstWhere('key', $this->failed_step);

        return [
            'id' => $this->id,
            'run_type' => $this->run_type->value,
            'requirement_summary' => Str::limit(trim(explode("\n", trim($this->requirement_text))[0] ?? ''), 80),
            'repo_config_id' => $this->repo_config_id,
            'repo_name' => $this->repoConfig->display_name,
            'target_file_path' => $this->target_file_path,
            'state' => $this->state->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Lineage for the "Edit" flow (plan J5): editing a run never mutates it — it
            // creates a new Run with previous_run_id set, so both ends of the edit stay
            // visible and each approval still maps to one fixed, unedited set of inputs.
            'requirement_text' => $this->requirement_text,
            'previous_run_id' => $this->previous_run_id,
            'previous_run_summary' => $this->previousRun
                ? Str::limit(trim(explode("\n", trim($this->previousRun->requirement_text))[0] ?? ''), 80)
                : null,

            // For coverage mode, testability_score doubles as the codebase-understanding score
            // and testability_review_markdown is null — the frontend reads
            // codebase_knowledge_base_markdown instead (see GapAnalysisReviewPanel.vue).
            'testability_score' => $this->testability_score,
            'testability_review_markdown' => $this->artifactOfKind(ArtifactKind::TestabilityReview->value)?->content,
            'codebase_knowledge_base_markdown' => $this->artifactOfKind(ArtifactKind::CodebaseKnowledgeBase->value)?->content,
            // "Build Skills" standalone action only — see RunStandaloneActionJob/PromptBuilder::
            // standaloneProjectSkill().
            'project_skill_markdown' => $this->artifactOfKind(ArtifactKind::ProjectSkill->value)?->content,
            'test_plan_markdown' => $this->artifactOfKind(ArtifactKind::TestPlan->value)?->content,

            'test_cases_markdown' => $this->artifactOfKind(ArtifactKind::TestCasesMarkdown->value)?->content,
            'test_case_verdict' => $this->test_case_verdict,

            'generated_file_path' => $this->generated_file_path,
            'generated_code' => $generatedCode,
            'has_multi_tenant_test' => $generatedCode ? $this->looksLikeItHasMultiTenantTest($generatedCode) : null,

            'execution_result' => $this->executionResult ? [
                'total' => $this->executionResult->total,
                'passed' => $this->executionResult->passed,
                'failed' => $this->executionResult->failed,
                'skipped' => $this->executionResult->skipped,
                'duration_ms' => $this->executionResult->duration_ms,
                'tests' => $this->executionResult->tests,
                // Null unless LocalTestRunner found a real PHPUnit HTML coverage page for this
                // run's target class (requirement-mode PHP/Laravel runs only — see its own
                // docblock) — served through RunController::coverageReport(), not a public
                // storage URL, since the report lives inside the connected repo's own checkout.
                'coverage_report_url' => $this->executionResult->coverage_report_path
                    ? url("/api/runs/{$this->id}/coverage-report/{$this->executionResult->coverage_report_path}")
                    : null,
            ] : null,

            'pr_url' => $this->pr_url,
            'ci_conclusion' => $this->ciCheckResult?->conclusion,

            'confidence_score' => $this->confidence_score,
            'confidence_breakdown' => $this->confidence_breakdown,
            // Available from Gate 2 onward (local execution has run, so testability/test-case
            // quality/local-execution are all known) — CI hasn't happened yet at that point, so
            // this excludes it and renormalizes rather than scoring it 0. Backs the "Download
            // HTML report" button in PushApprovalPanel.vue.
            'pre_push_confidence' => $this->executionResult
                ? app(ConfidenceScoreCalculator::class)->calculatePrePush($this->resource)
                : null,

            'blocked_reason' => $this->blocked_reason,
            'failed_step' => $this->failed_step,
            'failed_step_id' => $failedStep?->id,
            'error_message' => $this->error_message,
            'rejection_comment' => $this->rejection_comment,

            // Lets the frontend show a "Stopping…" indicator the instant a Stop click lands,
            // rather than waiting for the state to actually flip to `cancelled` once the
            // in-flight job notices — that can take up to ~1s (CancellationChecker's poll
            // interval), which felt unresponsive without this immediate signal.
            'cancel_requested_at' => $this->cancel_requested_at?->toIso8601String(),

            // Set aside without being deleted — see RunController::archive()'s own docblock.
            // The frontend uses this to suppress interactive gate panels (approve/reject/rerun)
            // on an archived run, since it may no longer safely own its repo's shared checkout.
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }

    /**
     * Best-effort heuristic only — flags for human attention at Gate 2, does not certify
     * coverage. `boschifai-gen-feature-php-laravel`'s own checklist mandates a cross-team access
     * test for team-scoped resources; RAMS's CLAUDE.md calls this its highest-consequence gap
     * category (plan §8), so it's surfaced rather than silently assumed present or absent.
     */
    private function looksLikeItHasMultiTenantTest(string $code): bool
    {
        return (bool) preg_match('/team|portfolio/i', $code)
            && (bool) preg_match('/403|forbidden|assertForbidden|assertUnauthorized/i', $code);
    }
}
