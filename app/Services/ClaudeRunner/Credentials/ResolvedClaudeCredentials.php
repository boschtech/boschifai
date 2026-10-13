<?php

namespace App\Services\ClaudeRunner\Credentials;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use Closure;

/**
 * What one `claude` invocation runs with: the provider variables to give the process, the model,
 * who pays, and which values must never be written to transcripts or logs.
 */
final class ResolvedClaudeCredentials
{
    /**
     * @param  array<string, string|null>  $env  provider variables for the process; null/empty
     *                                           values are left out (ClaudeProcessHardening)
     * @param  list<string>  $secretsToRedact  removed from the transcript and error output
     * @param  (Closure(): void)|null  $cleanup  run once the invocation has finished, e.g. to
     *                                           delete a temporary credentials file
     * @param  array<string, string>  $pinnedEnv  provider settings pinned over the repo's own
     *                                            (endpoint, region…) — see ClaudeProcessHardening
     */
    public function __construct(
        public readonly array $env,
        public readonly string $model,
        public readonly ClaudeBillingMode $billingMode,
        public readonly ClaudeCredentialSource $source,
        public readonly array $secretsToRedact = [],
        private readonly ?Closure $cleanup = null,
        public readonly array $pinnedEnv = [],
    ) {}

    public function cleanup(): void
    {
        if ($this->cleanup) {
            ($this->cleanup)();
        }
    }
}
