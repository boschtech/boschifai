<?php

namespace App\Services\ClaudeRunner;

/** Plain result value object returned by HeadlessClaudeInvoker::invoke(). */
class ClaudeInvocationResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly bool $timedOut,
        public readonly string $rawTranscriptPath,
        public readonly int $durationMs,
        public readonly ?float $totalCostUsd,
        public readonly ?int $numTurns,
        public readonly ?string $stopReason,
        public readonly string $stderr,
        public readonly bool $cancelled = false,
    ) {
    }

    public function succeeded(): bool
    {
        return ! $this->timedOut && ! $this->cancelled && $this->exitCode === 0;
    }

    /** For a guard that fires before any process was ever started (e.g. a pre-check). */
    public static function cancelledBeforeStart(): self
    {
        return new self(
            exitCode: -1,
            timedOut: false,
            rawTranscriptPath: '',
            durationMs: 0,
            totalCostUsd: null,
            numTurns: null,
            stopReason: null,
            stderr: '',
            cancelled: true,
        );
    }
}
