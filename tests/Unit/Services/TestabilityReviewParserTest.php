<?php

namespace Tests\Unit\Services;

use App\Services\ArtifactCollection\TestabilityReviewParser;
use Tests\TestCase;

class TestabilityReviewParserTest extends TestCase
{
    private TestabilityReviewParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TestabilityReviewParser();
    }

    public function test_extracts_score_from_the_real_header_table_format(): void
    {
        // Exact format confirmed against a real generated report during the plan's
        // verification spike — do not "clean up" this format assumption without re-confirming
        // against a live /boschifai-review output.
        $markdown = <<<MD
        # Testability Review — Custom Field Validation on Create

        | | |
        |:--|:--|
        | **Reviewed by** | Boschifai Test Analysis Agent |
        | **Testability Score** | 25% |
        | **Blocked Requirements** | 0 |
        MD;

        $this->assertSame(25, $this->parser->extractScore($markdown));
    }

    public function test_returns_null_when_score_row_is_absent(): void
    {
        $this->assertNull($this->parser->extractScore('# Some other document with no score table'));
    }

    /** @dataProvider bandProvider */
    public function test_band_matches_boschifai_review_standards_thresholds(int $score, string $expectedBand): void
    {
        $this->assertSame($expectedBand, $this->parser->band($score));
    }

    public static function bandProvider(): array
    {
        return [
            'green floor' => [80, 'Green'],
            'green ceiling' => [100, 'Green'],
            'yellow floor' => [60, 'Yellow'],
            'yellow ceiling' => [79, 'Yellow'],
            'red ceiling' => [59, 'Red'],
            'red floor' => [0, 'Red'],
        ];
    }
}
