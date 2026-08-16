<?php

namespace Tests\Unit\Services;

use App\Services\ArtifactCollection\CodebaseKnowledgeBaseParser;
use Tests\TestCase;

class CodebaseKnowledgeBaseParserTest extends TestCase
{
    private CodebaseKnowledgeBaseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CodebaseKnowledgeBaseParser();
    }

    public function test_extracts_score_from_the_header_table_format(): void
    {
        $markdown = <<<MD
        # Codebase Knowledge Base — billing module

        | | |
        |:--|:--|
        | **Codebase Understanding Score** | 72% |
        MD;

        $this->assertSame(72, $this->parser->extractScore($markdown));
    }

    public function test_score_returns_null_when_row_is_absent(): void
    {
        $this->assertNull($this->parser->extractScore('# No score table here'));
    }

    public function test_extracts_the_execution_recipe(): void
    {
        $markdown = <<<MD
        ## Execution Recipe

        ```
        INSTALL_COMMAND: composer install --no-interaction --no-progress -o
        TEST_COMMAND: php artisan test {{TEST_FILE}} --log-junit {{JUNIT_PATH}}
        ```
        MD;

        $this->assertSame([
            'install_command' => 'composer install --no-interaction --no-progress -o',
            'test_command' => 'php artisan test {{TEST_FILE}} --log-junit {{JUNIT_PATH}}',
        ], $this->parser->extractExecutionRecipe($markdown));
    }

    public function test_recipe_returns_null_when_either_line_is_missing(): void
    {
        $this->assertNull($this->parser->extractExecutionRecipe("INSTALL_COMMAND: npm install\nNo test command here."));
        $this->assertNull($this->parser->extractExecutionRecipe('# Nothing at all'));
    }
}
