<?php

namespace App\Services\Confidence;

use App\Models\Run;

/**
 * Composite confidence score (plan §7). Weights are a starting proposal for the team to tune,
 * not a validated formula — CI is weighted highest because it's the only fully independent,
 * real-infrastructure signal; the other three are the same generation engine checking its own
 * work to varying degrees.
 */
class ConfidenceScoreCalculator
{
    public function calculate(Run $run): array
    {
        $weights = config('boschifai.confidence_weights');

        $testability = $run->testability_score ?? 0;
        $testCaseQuality = $this->testCaseQualityScore($run);
        $localExecution = $run->executionResult ? $run->executionResult->passRate() : 0;
        $ci = match ($run->ciCheckResult?->conclusion) {
            'success' => 100,
            'failure', 'cancelled' => 0,
            default => 0,
        };

        $composite = round(
            ($testability * $weights['testability']
                + $testCaseQuality * $weights['test_case_quality']
                + $localExecution * $weights['local_execution']
                + $ci * $weights['ci'])
            / 100
        );

        return [
            'composite' => (int) $composite,
            'testability' => (int) $testability,
            'test_case_quality' => (int) $testCaseQuality,
            'local_execution' => (int) $localExecution,
            'ci' => (int) $ci,
        ];
    }

    /**
     * Same composite formula, minus the `ci` component — for a run at Gate 2 (local_execution_complete),
     * CI hasn't happened yet (there's nothing to push to CI until this gate is approved), so
     * `calculate()`'s own `ci: 0` default would understate this run's real standing rather than
     * just reflect "not run yet". Renormalizes the other three weights so they still sum to 100,
     * instead of arbitrarily deciding what CI "would have" scored.
     */
    public function calculatePrePush(Run $run): array
    {
        $weights = config('boschifai.confidence_weights');
        $availableWeight = $weights['testability'] + $weights['test_case_quality'] + $weights['local_execution'];

        $testability = $run->testability_score ?? 0;
        $testCaseQuality = $this->testCaseQualityScore($run);
        $localExecution = $run->executionResult ? $run->executionResult->passRate() : 0;

        $composite = round(
            ($testability * $weights['testability']
                + $testCaseQuality * $weights['test_case_quality']
                + $localExecution * $weights['local_execution'])
            / $availableWeight
        );

        return [
            'composite' => (int) $composite,
            'testability' => (int) $testability,
            'test_case_quality' => (int) $testCaseQuality,
            'local_execution' => (int) $localExecution,
        ];
    }

    private function testCaseQualityScore(Run $run): int
    {
        return match ($run->test_case_verdict) {
            'APPROVED' => 100,
            'NEEDS_FIXES' => 40, // reached Gate 2 with a known-unresolved validator finding
            default => 80, // verdict text wasn't found in the transcript — degrade, don't crash
        };
    }
}
