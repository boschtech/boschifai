<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The non-error paths of ClaudeTranscriptParser: summary/usage extraction, the sentinel-line
 * extractors each pipeline step relies on, and the live-activity tail feed.
 */
class ClaudeTranscriptParserTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** @param array<int, array|string> $lines arrays are JSON-encoded, strings written raw */
    private function transcript(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transcript');
        $this->paths[] = $path;

        file_put_contents($path, implode("\n", array_map(
            fn ($line) => is_array($line) ? json_encode($line) : $line,
            $lines,
        ))."\n");

        return $path;
    }

    private function assistantText(string $text): array
    {
        return ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]];
    }

    private function parser(): ClaudeTranscriptParser
    {
        return new ClaudeTranscriptParser;
    }

    public function test_parse_summary_returns_all_nulls_for_a_missing_file(): void
    {
        $summary = $this->parser()->parseSummary('/nonexistent/transcript.jsonl');

        $this->assertSame(array_fill_keys([
            'total_cost_usd', 'num_turns', 'stop_reason', 'input_tokens', 'output_tokens',
            'cache_creation_input_tokens', 'cache_read_input_tokens', 'error_message',
        ], null), $summary);
    }

    public function test_parse_summary_reads_cost_turns_stop_reason_and_usage_from_the_result_line(): void
    {
        $path = $this->transcript([
            'not json at all',
            $this->assistantText('Working...'),
            '',
            [
                'type' => 'result', 'subtype' => 'success', 'is_error' => false,
                'total_cost_usd' => '0.4567', 'num_turns' => '12', 'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 100, 'output_tokens' => 200,
                    'cache_creation_input_tokens' => 300, 'cache_read_input_tokens' => 400,
                ],
            ],
        ]);

        $summary = $this->parser()->parseSummary($path);

        $this->assertSame(0.4567, $summary['total_cost_usd']);
        $this->assertSame(12, $summary['num_turns']);
        $this->assertSame('end_turn', $summary['stop_reason']);
        $this->assertSame(100, $summary['input_tokens']);
        $this->assertSame(200, $summary['output_tokens']);
        $this->assertSame(300, $summary['cache_creation_input_tokens']);
        $this->assertSame(400, $summary['cache_read_input_tokens']);
        $this->assertNull($summary['error_message']);
    }

    public function test_parse_summary_falls_back_to_subtype_and_tolerates_partial_usage(): void
    {
        $path = $this->transcript([
            ['type' => 'result', 'subtype' => 'success', 'usage' => ['input_tokens' => 7]],
        ]);

        $summary = $this->parser()->parseSummary($path);

        $this->assertSame('success', $summary['stop_reason']);
        $this->assertNull($summary['total_cost_usd']);
        $this->assertNull($summary['num_turns']);
        $this->assertSame(7, $summary['input_tokens']);
        $this->assertNull($summary['output_tokens']);
        $this->assertNull($summary['cache_creation_input_tokens']);
        $this->assertNull($summary['cache_read_input_tokens']);
    }

    public function test_overloaded_errors_get_an_actionable_message(): void
    {
        $path = $this->transcript([
            ['type' => 'result', 'is_error' => true, 'result' => 'Overloaded', 'api_error_status' => 529],
        ]);

        $this->assertStringStartsWith(
            'The Anthropic API is temporarily overloaded.',
            $this->parser()->parseSummary($path)['error_message'],
        );
    }

    public function test_error_strings_in_the_errors_array_are_joined_into_the_message(): void
    {
        $path = $this->transcript([
            ['type' => 'result', 'subtype' => 'error_during_execution', 'errors' => ['first', 42, 'second']],
        ]);

        $this->assertSame(
            'Claude hit an error during execution. first; second',
            $this->parser()->parseSummary($path)['error_message'],
        );
    }

    public function test_an_error_with_no_subtype_and_no_detail_gets_a_generic_message(): void
    {
        $path = $this->transcript([['type' => 'result', 'is_error' => true]]);

        $this->assertSame('Claude reported an error with no further detail.', $this->parser()->parseSummary($path)['error_message']);
    }

    public function test_extract_test_case_verdict_reads_text_and_tool_result_blocks(): void
    {
        $fromText = $this->transcript([$this->assistantText("PASS: 10 | FAIL: 0\nVERDICT: APPROVED")]);
        $fromToolResult = $this->transcript([
            ['type' => 'user', 'message' => ['content' => [
                ['type' => 'tool_result', 'content' => [['type' => 'text', 'text' => 'VERDICT: NEEDS_FIXES']]],
            ]]],
        ]);
        $fromStringToolResult = $this->transcript([
            ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'VERDICT:   APPROVED']]]],
        ]);
        $none = $this->transcript([$this->assistantText('No verdict here'), ['type' => 'system'], 'garbage']);

        $this->assertSame('APPROVED', $this->parser()->extractTestCaseVerdict($fromText));
        $this->assertSame('NEEDS_FIXES', $this->parser()->extractTestCaseVerdict($fromToolResult));
        $this->assertSame('APPROVED', $this->parser()->extractTestCaseVerdict($fromStringToolResult));
        $this->assertNull($this->parser()->extractTestCaseVerdict($none));
        $this->assertNull($this->parser()->extractTestCaseVerdict('/nonexistent.jsonl'));
    }

    public function test_extract_recommended_target_file(): void
    {
        $path = $this->transcript([
            $this->assistantText('Analysis done.'),
            $this->assistantText("Summary\nRECOMMENDED_TARGET_FILE:   app/Services/Billing.php  \n"),
        ]);

        $this->assertSame('app/Services/Billing.php', $this->parser()->extractRecommendedTargetFile($path));
        $this->assertNull($this->parser()->extractRecommendedTargetFile($this->transcript([$this->assistantText('nothing')])));
        $this->assertNull($this->parser()->extractRecommendedTargetFile('/nonexistent.jsonl'));
    }

    public function test_recommended_target_file_must_start_a_line(): void
    {
        $path = $this->transcript([$this->assistantText('I would say RECOMMENDED_TARGET_FILE: app/Foo.php')]);

        $this->assertNull($this->parser()->extractRecommendedTargetFile($path));
    }

    public function test_extract_generated_test_file(): void
    {
        $path = $this->transcript([$this->assistantText("Done.\nGENERATED_TEST_FILE: tests/Unit/BillingTest.php")]);

        $this->assertSame('tests/Unit/BillingTest.php', $this->parser()->extractGeneratedTestFile($path));
        $this->assertNull($this->parser()->extractGeneratedTestFile($this->transcript([$this->assistantText('nothing')])));
        $this->assertNull($this->parser()->extractGeneratedTestFile('/nonexistent.jsonl'));
    }

    #[DataProvider('pushResultProvider')]
    public function test_extract_push_result(string $text, ?array $expected): void
    {
        $path = $this->transcript([$this->assistantText($text)]);

        $this->assertSame($expected, $this->parser()->extractPushResult($path));
    }

    public static function pushResultProvider(): array
    {
        return [
            'both lines' => [
                "Pushed.\nPR_URL: https://github.com/acme/backend/pull/42\nPR_NUMBER: 42",
                ['number' => 42, 'url' => 'https://github.com/acme/backend/pull/42'],
            ],
            'missing number' => ['PR_URL: https://github.com/acme/backend/pull/42', null],
            'missing url' => ['PR_NUMBER: 42', null],
            'non integer number' => ["PR_URL: https://x/pull/1\nPR_NUMBER: forty-two", null],
        ];
    }

    public function test_extract_push_result_is_null_for_a_missing_file(): void
    {
        $this->assertNull($this->parser()->extractPushResult('/nonexistent.jsonl'));
    }

    public function test_tail_events_summarises_text_and_tool_calls_but_skips_tool_results(): void
    {
        $path = $this->transcript([
            'not json',
            ['type' => 'system', 'subtype' => 'init'],
            ['type' => 'assistant', 'message' => 'not-an-array'],
            ['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => '  Reading the controller.  '],
                ['type' => 'text', 'text' => '   '],
                'not-a-block',
                ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'composer install']],
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'app/Foo.php']],
                ['type' => 'tool_use', 'name' => 'Skill', 'input' => ['skill' => 'boschifai-test-cases', 'args' => '--file app/Foo.php']],
                ['type' => 'tool_use', 'name' => 'Skill', 'input' => ['skill' => 'boschifai-review']],
                ['type' => 'tool_use', 'name' => 'Custom', 'input' => ['foo' => 'bar']],
                ['type' => 'tool_use', 'input' => 'raw string input'],
            ]]],
            ['type' => 'user', 'message' => ['content' => [
                ['type' => 'tool_result', 'content' => str_repeat('huge output ', 100)],
            ]]],
            ['type' => 'result', 'subtype' => 'success'],
        ]);

        $this->assertSame([
            'Reading the controller.',
            '→ Bash: composer install',
            '→ Read: app/Foo.php',
            '→ Skill: boschifai-test-cases --file app/Foo.php',
            '→ Skill: boschifai-review',
            '→ Custom: {"foo":"bar"}',
            '→ tool: raw string input',
        ], $this->parser()->tailEvents($path));
    }

    public function test_tail_events_keeps_only_the_most_recent_events_and_truncates_long_inputs(): void
    {
        $lines = [];
        for ($i = 1; $i <= 5; $i++) {
            $lines[] = $this->assistantText("event {$i}");
        }
        $lines[] = ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => str_repeat('x', 300)]],
        ]]];
        $path = $this->transcript($lines);

        $events = $this->parser()->tailEvents($path, 3);

        $this->assertSame(['event 4', 'event 5', '→ Bash: '.str_repeat('x', 140).'...'], $events);
        $this->assertSame([], $this->parser()->tailEvents('/nonexistent.jsonl'));
    }
}
