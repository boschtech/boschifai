<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\PromptBuilder;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    private PromptBuilder $prompts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prompts = new PromptBuilder();
    }

    public function test_codebase_analysis_references_the_instruction_file_and_execution_recipe_placeholders(): void
    {
        $prompt = $this->prompts->codebaseAnalysis('.boschifai/coverage_instruction_abc123.md');

        $this->assertStringContainsString('.boschifai/coverage_instruction_abc123.md', $prompt);
        $this->assertStringContainsString('codebase_knowledge_base_<name>', $prompt);
        $this->assertStringContainsString('Codebase Understanding Score', $prompt);
        $this->assertStringContainsString('INSTALL_COMMAND:', $prompt);
        $this->assertStringContainsString('TEST_COMMAND:', $prompt);
        $this->assertStringContainsString('{{TEST_FILE}}', $prompt);
        $this->assertStringContainsString('{{JUNIT_PATH}}', $prompt);
        $this->assertStringContainsString('boschifai-gen-component', $prompt);
        // Regression coverage for a real incident: a coverage-mode run's own recipe used
        // PHPUnit's `--filter={{TEST_FILE}}` (a file path passed as a test-name pattern), which
        // matches nothing and silently runs zero tests instead of failing loudly.
        $this->assertStringContainsString('--filter', $prompt);
        $this->assertStringContainsString('positional argument', $prompt);
    }

    public function test_gap_analysis_with_no_attachments_is_just_the_slash_command(): void
    {
        $prompt = $this->prompts->gapAnalysis('.boschifai/requirement_abc123.md');

        $this->assertSame('/boschifai-review --file .boschifai/requirement_abc123.md', $prompt);
    }

    public function test_gap_analysis_with_attachments_lists_them_before_the_slash_command(): void
    {
        $prompt = $this->prompts->gapAnalysis(
            '.boschifai/requirement_abc123.md',
            ['.boschifai/attachments/spec.pdf', '.boschifai/attachments/screenshot.png'],
        );

        $this->assertStringContainsString('supporting reference files', $prompt);
        $this->assertStringContainsString('.boschifai/attachments/spec.pdf', $prompt);
        $this->assertStringContainsString('.boschifai/attachments/screenshot.png', $prompt);
        $this->assertStringContainsString('/boschifai-review --file .boschifai/requirement_abc123.md', $prompt);
        // The attachments note must come BEFORE the slash command, not after — Claude Code
        // treats a `-p` prompt's own trailing content as what it acts on last.
        $this->assertLessThan(
            strpos($prompt, '/boschifai-review'),
            strpos($prompt, 'supporting reference files')
        );
    }

    public function test_coverage_test_design_references_both_input_files_and_the_recommended_target_marker(): void
    {
        $prompt = $this->prompts->coverageTestDesign(
            '.boschifai/coverage_instruction_abc123.md',
            '.boschifai/codebase_knowledge_base_abc123.md',
        );

        $this->assertStringContainsString('.boschifai/coverage_instruction_abc123.md', $prompt);
        $this->assertStringContainsString('.boschifai/codebase_knowledge_base_abc123.md', $prompt);
        $this->assertStringContainsString('boschifai-test-patterns', $prompt);
        $this->assertStringContainsString('RECOMMENDED_TARGET_FILE:', $prompt);
        // No literal, un-interpolated `{run_id}` placeholder should ever reach Claude.
        $this->assertStringNotContainsString('{run_id}', $prompt);
    }

    public function test_coverage_code_generation_references_the_target_file_and_test_cases(): void
    {
        $prompt = $this->prompts->coverageCodeGeneration(
            'app/Services/BillingService.php',
            '.boschifai/test_cases_abc123.md',
        );

        $this->assertStringContainsString('app/Services/BillingService.php', $prompt);
        $this->assertStringContainsString('.boschifai/test_cases_abc123.md', $prompt);
        $this->assertStringContainsString('boschifai-gen-component', $prompt);
        // Deliberately framework-neutral — must not hardcode PHPUnit wording the way the
        // requirement-mode codeGeneration() prompt does.
        $this->assertStringNotContainsString('PHPUnit', $prompt);
    }
}
