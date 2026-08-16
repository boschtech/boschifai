<?php

namespace App\Services\Sandbox;

use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Replaces the previous mirror-clone-plus-per-run-worktree design: every RepoConfig now gets
 * exactly ONE persistent working checkout that every Run against it shares and operates on
 * directly — generated tests are written straight into it and run there, no Docker, no
 * disposable per-run copy. This is a deliberate simplification requested directly (not a
 * fallback for something failing): local repos must never be cloned/reset at all (it's the
 * user's own real project), and GitHub-connected repos get a checkout Boschifai fully owns.
 *
 * Known, accepted limitation: because the checkout is shared, only one Run against a given repo
 * should be in flight at a time — RunController::store() enforces this at submission time
 * rather than this class trying to queue/lock concurrent access, which would be real added
 * complexity for a scenario this MVP doesn't need to solve yet.
 */
class RepoCheckoutManager
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    public function path(RepoConfig $repo): string
    {
        // A local repo already IS its own checkout — never a second copy of it.
        if ($repo->github_connection_id === null) {
            return $repo->git_remote_path;
        }

        return config('boschifai.var_path').'/repos/'.$repo->name;
    }

    /**
     * Local repos: returned as-is, untouched — no clone, no fetch, no reset, no clean. Anything
     * else here would mean force-modifying a real project the user is actively working in.
     *
     * GitHub-connected repos: cloned fresh if the checkout doesn't exist yet; otherwise fetched
     * and hard-reset to the latest `base_branch`, discarding anything left over from a previous
     * run (Boschifai owns this checkout entirely, so this is always safe here specifically).
     *
     * Auth: confirmed by live testing against a real (public) GitHub repo that `-c
     * http.extraheader="AUTHORIZATION: bearer <token>"` — the technique this and
     * BranchAndCommitService originally used, on the (untested until now) assumption it worked
     * the same way it does for many CI tools — actually fails outright in this environment
     * (`fatal: could not read Username`), regardless of header capitalization. Embedding the
     * token directly in the clone/fetch URL is what actually works; the credential is never
     * persisted to disk, though — the checkout's stored `origin` remote is scrubbed back to a
     * clean URL immediately after cloning, and a fetch uses the credentialed URL as an explicit
     * one-off argument rather than ever writing it into `origin`'s config.
     */
    public function ensureReady(RepoConfig $repo): string
    {
        $path = $this->path($repo);

        if ($repo->github_connection_id === null) {
            return $path;
        }

        $cleanUrl = $repo->git_remote_path;
        $credentialedUrl = $this->credentialedUrl($repo);

        if (! File::isDirectory($path)) {
            File::ensureDirectoryExists(dirname($path));
            $this->run(['git', 'clone', '--branch', $repo->base_branch, $credentialedUrl, $path], null);
            $this->run(['git', 'remote', 'set-url', 'origin', $cleanUrl], $path);

            return $path;
        }

        $this->run(['git', 'fetch', $credentialedUrl, "+{$repo->base_branch}:refs/remotes/origin/{$repo->base_branch}"], $path);
        $this->run(['git', 'checkout', $repo->base_branch], $path);
        $this->run(['git', 'reset', '--hard', "origin/{$repo->base_branch}"], $path);
        $this->run(['git', 'clean', '-fd'], $path);

        return $path;
    }

    /** The clone/fetch URL with the connected account's OAuth token embedded as the username. */
    private function credentialedUrl(RepoConfig $repo): string
    {
        $token = $this->githubAuth->tokenFor($repo);

        return preg_replace('#^https://#', "https://{$token}@", $repo->git_remote_path);
    }

    private function run(array $command, ?string $cwd): void
    {
        $pendingProcess = Process::timeout(120);
        if ($cwd !== null) {
            $pendingProcess = $pendingProcess->path($cwd);
        }
        $process = $pendingProcess->run($command);

        if ($process->failed()) {
            throw new RuntimeException(
                'Git command failed: '.implode(' ', $this->redact($command))."\n".$this->redactString($process->errorOutput())
            );
        }
    }

    /**
     * The OAuth token embedded in a credentialed clone/fetch URL must never land in an
     * exception message/log — those get surfaced to the run's error_message column and this
     * class's own callers, neither of which should ever hold a live credential. Git's own error
     * output can also echo the URL it failed against, so that needs redacting too.
     */
    private function redact(array $command): array
    {
        return array_map(fn (string $arg) => $this->redactString($arg), $command);
    }

    private function redactString(string $value): string
    {
        return preg_replace('#https://[^/@\s]+@#', 'https://[redacted]@', $value);
    }
}
