<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use Tests\TestCase;

/**
 * Claude Code reports API/run failures in the stream-json "result" event on stdout, not stderr
 * — these pin down that parseSummary() surfaces them as error_message.
 */
class ClaudeTranscriptParserErrorTest extends TestCase
{
    private function summaryFor(array $resultEvent): array
    {
        $path = tempnam(sys_get_temp_dir(), 'transcript');
        file_put_contents($path, json_encode(['type' => 'assistant', 'message' => []])."\n".json_encode(['type' => 'result'] + $resultEvent)."\n");

        try {
            return (new ClaudeTranscriptParser)->parseSummary($path);
        } finally {
            unlink($path);
        }
    }

    public function test_an_api_error_reported_with_a_success_subtype_is_still_surfaced(): void
    {
        // Real shape observed from a failed test_plan step.
        $summary = $this->summaryFor([
            'subtype' => 'success', 'is_error' => true, 'result' => 'Credit balance is too low', 'api_error_status' => 400,
        ]);

        $this->assertStringStartsWith('The Anthropic API account used by BoschifAI has run out of credit.', $summary['error_message']);
        $this->assertStringContainsString('Retry step', $summary['error_message']);
        // Raw API text kept for diagnosis.
        $this->assertStringContainsString('(Claude API error (HTTP 400): Credit balance is too low)', $summary['error_message']);
    }

    public function test_max_turns_is_explained(): void
    {
        $summary = $this->summaryFor(['subtype' => 'error_max_turns', 'is_error' => true]);

        $this->assertStringContainsString('maximum number of turns', $summary['error_message']);
    }

    public function test_an_error_with_no_detail_still_gets_a_message(): void
    {
        $summary = $this->summaryFor(['subtype' => 'error_during_execution']);

        $this->assertNotEmpty($summary['error_message']);
    }

    public function test_a_successful_result_has_no_error(): void
    {
        $summary = $this->summaryFor(['subtype' => 'success', 'is_error' => false, 'result' => 'Done.']);

        $this->assertNull($summary['error_message']);
    }

    public function test_rate_limits_and_bad_keys_get_an_actionable_message(): void
    {
        $this->assertStringContainsString('rate limit', $this->summaryFor(['is_error' => true, 'result' => 'Too many requests', 'api_error_status' => 429])['error_message']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $this->summaryFor(['is_error' => true, 'result' => 'invalid x-api-key', 'api_error_status' => 401])['error_message']);
    }

    public function test_an_unrecognised_api_error_keeps_the_raw_text(): void
    {
        $summary = $this->summaryFor(['is_error' => true, 'result' => 'Something odd', 'api_error_status' => 500]);

        $this->assertSame('Claude API error (HTTP 500): Something odd', $summary['error_message']);
    }
}
