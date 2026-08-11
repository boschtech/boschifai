<?php

namespace App\Services\Pipeline;

use App\Models\RunArtifact;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use Illuminate\Support\Collection;

class StepOutcome
{
    /** @param Collection<int, RunArtifact> $artifacts */
    public function __construct(
        public readonly ClaudeInvocationResult $claudeResult,
        public readonly Collection $artifacts,
    ) {
    }

    public function artifactOfKind(string $kind): ?RunArtifact
    {
        return $this->artifacts->first(fn (RunArtifact $a) => $a->kind->value === $kind);
    }
}
