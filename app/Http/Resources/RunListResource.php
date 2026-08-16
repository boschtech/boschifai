<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RunListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_type' => $this->run_type->value,
            'requirement_summary' => Str::limit(trim(explode("\n", trim($this->requirement_text))[0] ?? ''), 80),
            'repo_name' => $this->repoConfig->display_name,
            'state' => $this->state->value,
            'confidence_score' => $this->confidence_score,
            // Below: added for the Reporting section's three list pages (Confidence/Coverage/Test
            // History) — deliberately reusing this one already-fetched list rather than adding
            // per-report endpoints, since every field here already lives on Run/its two 1:1
            // relations and RunController::index() already loads the whole set unpaginated.
            'confidence_breakdown' => $this->confidence_breakdown,
            'testability_score' => $this->testability_score,
            'test_case_verdict' => $this->test_case_verdict,
            'generated_file_path' => $this->generated_file_path,
            'target_file_path' => $this->target_file_path,
            'pr_url' => $this->pr_url,
            'ci_conclusion' => $this->ciCheckResult?->conclusion,
            'execution_result' => $this->executionResult ? [
                'total' => $this->executionResult->total,
                'passed' => $this->executionResult->passed,
                'failed' => $this->executionResult->failed,
                'skipped' => $this->executionResult->skipped,
                'duration_ms' => $this->executionResult->duration_ms,
                'coverage_report_url' => $this->executionResult->coverage_report_path
                    ? url("/api/runs/{$this->id}/coverage-report/{$this->executionResult->coverage_report_path}")
                    : null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
