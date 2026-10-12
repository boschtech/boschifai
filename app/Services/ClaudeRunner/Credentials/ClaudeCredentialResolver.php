<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Models\Run;

/**
 * Decides which AI credentials a pipeline step runs with. Today every step uses the platform's
 * own key (PlatformCredentialResolver); Phase 3 binds a resolver that can use a workspace's own
 * Anthropic key, AWS Bedrock or Google Vertex instead — without the invoker or the jobs changing.
 */
interface ClaudeCredentialResolver
{
    public function resolveFor(Run $run, string $stepKey): ResolvedClaudeCredentials;
}
