<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\ClaudeInvocationResult;
use Tests\TestCase;

class ClaudeInvocationResultTest extends TestCase
{
    public function test_a_cancelled_result_never_counts_as_succeeded_even_with_a_zero_exit_code(): void
    {
        // A real edge case worth pinning down: SIGTERM can, in principle, still leave a process
        // that reports exit code 0 depending on how it handles the signal. `cancelled` must be
        // an independent override, not something inferred solely from a non-zero exit code.
        $result = new ClaudeInvocationResult(
            exitCode: 0,
            timedOut: false,
            rawTranscriptPath: '/tmp/x.jsonl',
            durationMs: 1000,
            totalCostUsd: null,
            numTurns: null,
            stopReason: null,
            stderr: '',
            cancelled: true,
        );

        $this->assertFalse($result->succeeded());
    }

    public function test_cancelled_before_start_factory_produces_a_result_that_never_succeeded(): void
    {
        $result = ClaudeInvocationResult::cancelledBeforeStart();

        $this->assertTrue($result->cancelled);
        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->timedOut);
    }

    public function test_an_ordinary_successful_result_is_unaffected(): void
    {
        $result = new ClaudeInvocationResult(
            exitCode: 0,
            timedOut: false,
            rawTranscriptPath: '/tmp/x.jsonl',
            durationMs: 1000,
            totalCostUsd: 0.05,
            numTurns: 3,
            stopReason: 'end_turn',
            stderr: '',
        );

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->cancelled);
    }

    private function failed(array $overrides = []): ClaudeInvocationResult
    {
        return new ClaudeInvocationResult(...array_merge([
            'exitCode' => 1,
            'timedOut' => false,
            'rawTranscriptPath' => '/tmp/x.jsonl',
            'durationMs' => 1000,
            'totalCostUsd' => null,
            'numTurns' => null,
            'stopReason' => null,
            'stderr' => '',
        ], $overrides));
    }

    public function test_failure_reason_prefers_claudes_own_reported_error(): void
    {
        $result = $this->failed(['resultError' => 'Claude API error (HTTP 400): Credit balance is too low']);

        $this->assertSame('Claude API error (HTTP 400): Credit balance is too low', $result->failureReason());
    }

    public function test_a_reported_error_is_a_failure_even_with_a_zero_exit_code(): void
    {
        $this->assertFalse($this->failed(['exitCode' => 0, 'resultError' => 'Rate limited'])->succeeded());
    }

    public function test_failure_reason_explains_running_out_of_output_tokens(): void
    {
        $this->assertStringContainsString('max_tokens', $this->failed(['stopReason' => 'max_tokens'])->failureReason());
    }

    public function test_failure_reason_explains_a_timeout_even_with_empty_stderr(): void
    {
        $reason = $this->failed(['timedOut' => true, 'durationMs' => 600000])->failureReason();

        $this->assertStringContainsString('Timed out after 600s', $reason);
    }

    public function test_failure_reason_falls_back_to_stderr_then_exit_code(): void
    {
        $this->assertSame('boom', $this->failed(['stderr' => "boom\n"])->failureReason());
        $this->assertSame('Claude exited with code 1 without reporting a reason.', $this->failed()->failureReason());
    }
}
