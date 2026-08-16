<?php

namespace App\Services\ClaudeRunner;

use App\Enums\RunStepKey;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Shells out to `claude -p` non-interactively, inside the given worktree. Confirmed working
 * against a real invocation during the plan's verification spike (`/boschifai-review` produced a real
 * testability_review_*.md) — that spike also confirmed the flags below exist on the installed
 * CLI version (`claude --help`): -p/--print, --output-format, --permission-mode, --model.
 *
 * TODO (flagged, not built here): production should run this inside a locked-down container
 * (network egress restricted to api.anthropic.com only) per plan §3 — `boschifai.claude.docker_image`
 * names the intended image, but building/hardening that image is real infra work requiring
 * engineering sign-off, not something to assume done. This class currently execs `claude`
 * directly on the host running the queue worker.
 *
 * GitHub MCP server: only wired in (via --mcp-config, below) when a PAT is actually configured
 * (config('boschifai.github_mcp.pat')) — every invocation gets the same server available, but
 * PushAndOpenPrJob's MCP-based push path is the only caller that deliberately uses it with a
 * tightly scoped, code-authored prompt (exact branch/file/PR title/body, explicit "no other
 * tool calls" instruction) specifically because this now puts push credentials in the same
 * process as agentic code — a tradeoff BranchAndCommitService's own docblock originally avoided
 * entirely. See that job for the compensating controls (local commit prepared and verified
 * BEFORE Claude runs, PR contents verified via the REST API after).
 *
 * `--allowedTools` for the push step only: confirmed as a real bug live — `--permission-mode
 * acceptEdits` only pre-approves file Edit/Write, not MCP tool calls, so `mcp__github__
 * create_branch` stalled on "needs your approval before I can proceed" with no human available
 * to grant it, identical in shape to the earlier `git push`-via-Bash bug this class already works
 * around. Rather than reaching for `--dangerously-skip-permissions` (which would also silently
 * approve the exact `git`/Bash commands the push prompt explicitly forbids), only the specific
 * mcp__github__* tools that constrained prompt actually needs are pre-approved — nothing else
 * gets a wider grant. Generation-side steps pass no stepKey-specific allowlist and keep today's
 * fully agentic behaviour unchanged.
 *
 * PUSH_STEP_ALLOWED_TOOLS lists BOTH `create_or_update_file` and `push_files`: confirmed as a
 * real bug live — the github-mcp-server exposes both a single-file and a multi-file commit tool,
 * and which one Claude reaches for isn't pinned down by the prompt (observed it pick
 * `push_files` for what the prompt describes as writing one file). Allowlisting only one left
 * the other stalled on approval exactly like `create_branch` originally did.
 */
class HeadlessClaudeInvoker
{
    /** How often the cancellation flag is re-checked from the DB while polling the process. */
    private const CANCEL_CHECK_INTERVAL_SECONDS = 1.0;

    /** How long the process loop sleeps between polls — cheap, just watching for exit/output. */
    private const POLL_INTERVAL_MICROSECONDS = 300_000;

    /** Tools the constrained push prompt needs pre-approved — nothing wider (see class docblock). */
    private const PUSH_STEP_ALLOWED_TOOLS = [
        'mcp__github__create_branch',
        'mcp__github__create_or_update_file',
        'mcp__github__push_files',
        'mcp__github__create_pull_request',
    ];

    public function __construct(private ClaudeTranscriptParser $parser)
    {
    }

    /**
     * @param callable(): bool $isCancelled polled periodically while the process runs; when it
     *                                       returns true the process is stopped and the result
     *                                       carries `cancelled: true` instead of a failure.
     */
    public function invoke(
        string $worktreePath,
        string $prompt,
        string $transcriptPath,
        int $timeoutSeconds,
        callable $isCancelled,
        ?string $stepKey = null,
    ): ClaudeInvocationResult {
        File::ensureDirectoryExists(dirname($transcriptPath));
        // Truncate/create up front — the callback below appends as output arrives, so the file
        // must exist (empty) before the process starts for a concurrent reader to find it.
        File::put($transcriptPath, '');

        $model = config('boschifai.claude.model');

        $started = microtime(true);
        $errorOutput = '';

        // Confirmed as a real bug via a live user report: `Process::run()` only returns output
        // once the process has fully exited, so writing the transcript from `$process->output()`
        // afterward meant nothing existed to tail WHILE Claude was still running — a "live
        // activity" panel with genuinely nothing live in it. The output callback here fires
        // incrementally as stdout arrives, so the transcript file grows in near-real-time and a
        // concurrent request (the activity endpoint) can tail it mid-run. A line may be read
        // mid-write and fail json_decode — expected and harmless, every reader here already
        // skips undecodable lines.
        $githubMcpPat = config('boschifai.github_mcp.pat');

        $invoked = Process::path($worktreePath)
            ->timeout($timeoutSeconds) // defense-in-depth only — see note on manual deadline below
            ->env(array_filter([
                'ANTHROPIC_API_KEY' => config('boschifai.claude.api_key'),
                // Read by the github-mcp-server subprocess `claude` itself spawns (see
                // mcp-config.json) via normal child-process environment inheritance — never
                // written into that config file, which stays secret-free and safe to bake into
                // the image/commit to version control.
                'GITHUB_PERSONAL_ACCESS_TOKEN' => $githubMcpPat,
            ]))
            ->start([
                'claude', '-p', $prompt,
                '--output-format', 'stream-json',
                '--verbose',
                '--permission-mode', 'acceptEdits',
                '--model', $model,
                // Only when a PAT is actually configured — otherwise the github-mcp-server
                // subprocess would start with no credential, fail to authenticate, and this
                // invocation would pay `--mcp-config`'s own startup-sync wait (up to 30s via
                // MCP_TIMEOUT) for a server nobody set up yet.
                ...($githubMcpPat ? ['--mcp-config', config('boschifai.github_mcp.config_path')] : []),
                ...($githubMcpPat && $stepKey === RunStepKey::Push->value
                    ? ['--allowedTools', implode(',', self::PUSH_STEP_ALLOWED_TOOLS)]
                    : []),
            ], function (string $type, string $bytes) use ($transcriptPath, &$errorOutput) {
                if ($type === \Symfony\Component\Process\Process::OUT) {
                    File::append($transcriptPath, $bytes);
                } else {
                    $errorOutput .= $bytes;
                }
            });

        // Manual deadline tracking, not reliance on Symfony's built-in timeout: confirmed by
        // reading Symfony\Process\Process source that `isRunning()` (what `InvokedProcess::
        // running()` calls) does NOT call `checkTimeout()` — only `wait()`'s own internal loop
        // does. Since this polls via `running()` in a loop rather than blocking on `wait()`,
        // the timeout would silently never fire without tracking it here directly. `->timeout()`
        // above is kept as a defensive backstop only, not the primary enforcement.
        $deadline = $started + $timeoutSeconds;
        $lastCancelCheck = 0.0;
        $cancelled = false;
        $timedOut = false;

        while ($invoked->running()) {
            $now = microtime(true);

            if ($now >= $deadline) {
                $invoked->stop(3);
                $timedOut = true;
                break;
            }

            if ($now - $lastCancelCheck >= self::CANCEL_CHECK_INTERVAL_SECONDS) {
                $lastCancelCheck = $now;
                if ($isCancelled()) {
                    $invoked->stop(3);
                    $cancelled = true;
                    break;
                }
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        try {
            $result = $invoked->wait();
            $exitCode = $result->exitCode() ?? -1;
        } catch (ProcessTimedOutException $e) {
            // Belt-and-braces: only reachable if Symfony's own timeout fired independently of
            // the manual deadline above (e.g. a race right at the boundary).
            $timedOut = true;
            $exitCode = -1;
            $errorOutput = $errorOutput !== '' ? $errorOutput : $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $summary = ($timedOut || $cancelled)
            ? ['total_cost_usd' => null, 'num_turns' => null, 'stop_reason' => null]
            : $this->parser->parseSummary($transcriptPath);

        return new ClaudeInvocationResult(
            exitCode: $exitCode,
            timedOut: $timedOut,
            rawTranscriptPath: $transcriptPath,
            durationMs: $durationMs,
            totalCostUsd: $summary['total_cost_usd'],
            numTurns: $summary['num_turns'],
            stopReason: $summary['stop_reason'],
            stderr: $errorOutput,
            cancelled: $cancelled,
        );
    }

    public function transcriptPathFor(string $runId, string $stepKey): string
    {
        return storage_path("app/boschifai-runs/{$runId}/claude/{$stepKey}-".Str::random(8).'.jsonl');
    }
}
