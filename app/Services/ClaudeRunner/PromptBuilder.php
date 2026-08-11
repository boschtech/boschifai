<?php

namespace App\Services\ClaudeRunner;

/**
 * Builds the four prompts for the MVP pipeline (plan §4). Paths passed in are relative to the
 * worktree root (Claude's cwd), matching how the requirement file and generated artifacts are
 * addressed inside `.boschifai/`.
 */
class PromptBuilder
{
    public function gapAnalysis(string $requirementRelativePath): string
    {
        return "/boschifai-review --file {$requirementRelativePath}";
    }

    public function testPlan(string $requirementRelativePath): string
    {
        return "/boschifai-test-plan --file {$requirementRelativePath}";
    }

    /**
     * Judgment call J2 (plan §3): `/boschifai-test-cases` has a HARD-GATE requiring `/boschifai-review` to
     * have run "in this session". Gate 1's human approval necessarily ends that session, so
     * this prompt must explicitly invoke the command's own documented escape hatch — without
     * it the command would either silently re-run /boschifai-review (wasted cost, and it would try to
     * rewrite a file a human already approved) or stall.
     */
    public function testCaseGeneration(string $requirementRelativePath, string $testabilityReviewRelativePath): string
    {
        return <<<PROMPT
        A testability review has already been completed and approved externally — see
        {$testabilityReviewRelativePath} in this directory. Skip the HARD-GATE re-check and
        proceed directly to test case generation.

        /boschifai-test-cases --file {$requirementRelativePath}
        PROMPT;
    }

    public function codeGeneration(string $targetFilePath, string $testCasesRelativePath): string
    {
        return <<<PROMPT
        Test cases for this requirement have already been generated and approved — see
        {$testCasesRelativePath} in this directory. Implement PHPUnit Feature tests covering
        each test case already defined there.

        /boschifai-gen-component --file {$targetFilePath}
        PROMPT;
    }
}
