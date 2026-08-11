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
}
