<?php

namespace App\Services\ArtifactCollection;

use App\Enums\ArtifactKind;

/**
 * Finds what a Claude invocation actually produced by diffing `git status --porcelain`
 * snapshots (RunWorkspaceManager::statusPaths) before/after — never by parsing a transcript for
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

        if (str_starts_with($basename, 'codebase_knowledge_base_') && str_ends_with($basename, '.md')) {
            return ArtifactKind::CodebaseKnowledgeBase;
        }

        if (str_starts_with($basename, 'project_skill_') && str_ends_with($basename, '.md')) {
            return ArtifactKind::ProjectSkill;
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

        if ($this->looksLikeGeneratedTest($basename, $relativePath)) {
            return ArtifactKind::GeneratedTest;
        }

        return null;
    }

    /**
     * Coverage-mode runs can target any stack `/boschifai-gen-component` knows how to detect
     * (see that command's own signal-based routing), so this can no longer assume PHPUnit's
     * `tests/Feature|Unit/*Test.php` convention is the only shape a generated test can take.
     * Each per-language pattern below is that ecosystem's own de facto test-discovery
     * convention, not something invented here.
     *
     * Public: also used by RunTestPlanJob to reject a coverage-mode RECOMMENDED_TARGET_FILE that
     * looks like a test file itself rather than application source — confirmed as a real failure
     * mode live (a re-run against a controller that already had a generated test recommended
     * that test file back as its own target; /boschifai-gen-component then EDITED the
     * already-untracked file in place instead of creating a new one, which never looks "new" to
     * classifyAll()'s own diff, producing a confusing "no file detected" failure two steps later).
     */
    public function looksLikeGeneratedTest(string $basename, string $relativePath): bool
    {
        // PHP (PHPUnit/Laravel) — kept directory-scoped since the "*Test.php" suffix alone is
        // too generic outside a real test directory (e.g. a "ContractTest.php" value object).
        if (str_ends_with($basename, 'Test.php')
            && (str_contains($relativePath, 'tests/Feature/') || str_contains($relativePath, 'tests/Unit/'))) {
            return true;
        }

        // Java (JUnit) — src/test/java is the standard Maven/Gradle test source root.
        if (str_ends_with($basename, 'Test.java') && str_contains($relativePath, 'src/test/java/')) {
            return true;
        }

        // JS/TS (Jest, Vitest, etc.) — .test./.spec. suffixes are that ecosystem's own
        // discovery convention regardless of directory.
        if (preg_match('/\.(test|spec)\.(js|jsx|ts|tsx)$/', $basename) === 1) {
            return true;
        }

        // Python (pytest) — test_*.py / *_test.py are pytest's own default discovery patterns.
        if (preg_match('/^test_.+\.py$/', $basename) === 1 || preg_match('/_test\.py$/', $basename) === 1) {
            return true;
        }

        // Dart/Flutter — _test.dart is the `test`/`flutter_test` package's own convention.
        if (str_ends_with($basename, '_test.dart')) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, string> relativePath => classified kind value, for paths that
     *                                matched a known convention. Unmatched new paths (e.g.
     *                                boschifai init's own scaffolding, if the caller's baseline was
     *                                taken too early) are silently excluded, not errored —
     *                                see RunWorkspaceManager::prepare()'s baseline-timing note.
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
