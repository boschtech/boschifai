<?php

namespace App\Services\Pipeline;

use App\Models\RunArtifact;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use Illuminate\Support\Collection;

class StepOutcome
{
    /**
     * @param  Collection<int, RunArtifact>  $artifacts
     * @param  bool  $halted  the step didn't run and the run has already been moved to a waiting
     *                        state (e.g. awaiting_credits) — the calling job just stops
     */
    public function __construct(
        public readonly ClaudeInvocationResult $claudeResult,
        public readonly Collection $artifacts,
        public readonly bool $halted = false,
    ) {}

    public static function halted(string $reason): self
    {
        return new self(ClaudeInvocationResult::notStarted($reason), collect(), halted: true);
    }

    public function artifactOfKind(string $kind): ?RunArtifact
    {
        return $this->artifacts->first(fn (RunArtifact $a) => $a->kind->value === $kind);
    }
}
