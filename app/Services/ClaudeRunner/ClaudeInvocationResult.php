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
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $cacheCreationInputTokens = null,
        public readonly ?int $cacheReadInputTokens = null,
        // Claude's own reported failure from the transcript's "result" event (see
        // ClaudeTranscriptParser::resultErrorMessage) — API errors such as low credit land here,
        // not in $stderr.
        public readonly ?string $resultError = null,
    ) {}

    public function succeeded(): bool
    {
        return ! $this->timedOut && ! $this->cancelled && $this->exitCode === 0 && $this->resultError === null;
    }

    /**
     * Human-readable reason for a failed invocation — never empty, so a failed step always
     * tells the reviewer why. Most specific source first: Claude's own reported error, then
     * running out of output tokens, then a timeout, then whatever the CLI wrote to stderr.
     */
    public function failureReason(): string
    {
        $reasons = array_filter([
            $this->resultError,
            $this->stopReason === 'max_tokens'
                ? 'Claude ran out of output tokens (max_tokens) before finishing.'
                : null,
            $this->timedOut
                ? 'Timed out after '.round($this->durationMs / 1000).'s — the step did not finish within its time limit.'
                : null,
            trim($this->stderr) !== '' ? trim($this->stderr) : null,
        ]);

        if ($reasons !== []) {
            return implode("\n", array_unique($reasons));
        }

        return "Claude exited with code {$this->exitCode} without reporting a reason.";
    }

    /** For a guard that fires before any process was ever started (e.g. a pre-check). */
    /** For a step that never started because something outside Claude stopped it first. */
    public static function notStarted(string $reason): self
    {
        return new self(
            exitCode: -1,
            timedOut: false,
            rawTranscriptPath: '',
            durationMs: 0,
            totalCostUsd: null,
            numTurns: null,
            stopReason: null,
            stderr: $reason,
        );
    }

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
