<?php

namespace App\Services\ArtifactCollection;

/**
 * Extracts the two things a coverage-mode Run needs out of a codebase_knowledge_base_*.md
 * artifact: a self-assessed understanding score (same header-table convention
 * TestabilityReviewParser already uses, so it renders identically in the confidence report) and
 * the execution recipe — the install command plus a single-test-file run command, the latter
 * templated with {{TEST_FILE}}/{{JUNIT_PATH}} placeholders — that LocalTestRunner substitutes
 * into instead of its hardcoded PHPUnit command. See PromptBuilder::codebaseAnalysis() for the
 * exact prompt instructing Claude to produce both in this format.
 */
class CodebaseKnowledgeBaseParser
{
    public function extractScore(string $markdown): ?int
    {
        if (preg_match('/\*\*Codebase Understanding Score\*\*\s*\|\s*(\d{1,3})%/', $markdown, $matches)) {
            return min(100, (int) $matches[1]);
        }

        return null;
    }

    /** @return array{install_command: string, test_command: string}|null */
    public function extractExecutionRecipe(string $markdown): ?array
    {
        $hasInstall = preg_match('/^INSTALL_COMMAND:\s*(.+)$/m', $markdown, $installMatch);
        $hasTest = preg_match('/^TEST_COMMAND:\s*(.+)$/m', $markdown, $testMatch);

        if (! $hasInstall || ! $hasTest) {
            return null;
        }

        return [
            'install_command' => trim($installMatch[1]),
            'test_command' => trim($testMatch[1]),
        ];
    }
}
