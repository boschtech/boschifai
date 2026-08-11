<?php

namespace App\Services\ArtifactCollection;

use App\Enums\ArtifactKind;

/**
 * Finds what a Claude invocation actually produced by diffing `git status --porcelain`
 * snapshots (WorktreeManager::statusPaths) before/after — never by parsing a transcript for
 * file paths, which the plan explicitly calls out as fragile (see plan §3).
 */
class GitStatusDiffCollector
{
    /** @return string[] paths present in $after but not $before */
    public function newPaths(array $before, array $after): array
    {
        return array_values(array_diff($after, $before));
    }

    public function classify(string $relativePath): ?ArtifactKind
    {
        $basename = basename($relativePath);

        if (str_starts_with($basename, 'testability_review_') && str_ends_with($basename, '.md')) {
            return ArtifactKind::TestabilityReview;
        }

        if (str_starts_with($basename, 'test_plan_') && str_ends_with($basename, '.md')) {
            return ArtifactKind::TestPlan;
        }

        if (str_starts_with($basename, 'test_cases_') && str_ends_with($basename, '.md')) {
            return ArtifactKind::TestCasesMarkdown;
        }

        if (str_starts_with($basename, 'test_cases_') && str_ends_with($basename, '.json')) {
            return ArtifactKind::TestCasesJson;
        }

        if (str_ends_with($basename, 'Test.php')
            && (str_contains($relativePath, 'tests/Feature/') || str_contains($relativePath, 'tests/Unit/'))) {
            return ArtifactKind::GeneratedTestPhp;
        }

        return null;
    }

    /**
     * @return array<string, string> relativePath => classified kind value, for paths that
     *                                matched a known convention. Unmatched new paths (e.g.
     *                                boschifai init's own scaffolding, if the caller's baseline was
     *                                taken too early) are silently excluded, not errored —
     *                                see WorktreeManager::create()'s baseline-timing note.
     */
    public function classifyAll(array $newPaths): array
    {
        $classified = [];
        foreach ($newPaths as $path) {
            $kind = $this->classify($path);
            if ($kind !== null) {
                $classified[$path] = $kind->value;
            }
        }

        return $classified;
    }
}
