<?php

namespace App\Services\ArtifactCollection;

/**
 * Extracts the numeric score from a testability_review_*.md header table row, e.g.:
 *   | **Testability Score** | 25% |
 * Format confirmed against a real generated report during the plan's verification spike.
 */
class TestabilityReviewParser
{
    public function extractScore(string $markdown): ?int
    {
        if (preg_match('/\*\*Testability Score\*\*\s*\|\s*(\d{1,3})%/', $markdown, $matches)) {
            return min(100, (int) $matches[1]);
        }

        return null;
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Green',
            $score >= 60 => 'Yellow',
            default => 'Red',
        };
    }
}
