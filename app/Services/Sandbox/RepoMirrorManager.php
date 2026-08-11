<?php

namespace App\Services\Sandbox;

use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Maintains one `git clone --mirror` per RepoConfig, refreshed via `git fetch` before each
 * Run. Worktrees (see WorktreeManager) are created off this mirror rather than a fresh
 * clone per run — cheap, and every worktree shares the same object store.
 */
class RepoMirrorManager
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    public function mirrorPath(RepoConfig $repo): string
    {
        return config('boschifai.var_path').'/repos/'.$repo->name.'.git';
    }

    public function ensureFresh(RepoConfig $repo): string
    {
        $path = $this->mirrorPath($repo);
        $authArgs = $this->authArgsFor($repo);

        if (! File::isDirectory($path)) {
            File::ensureDirectoryExists(dirname($path));
            $this->run(['git', ...$authArgs, 'clone', '--mirror', $repo->git_remote_path, $path], null);

            return $path;
        }

        $this->run(['git', ...$authArgs, 'fetch', '--prune', 'origin', '+refs/heads/*:refs/heads/*'], $path);

        return $path;
    }

    /**
     * Empty for the local-path "rams" row — identical behavior to before this method existed.
     * A GitHub-connected repo's git_remote_path is an HTTPS URL requiring the connected
     * account's OAuth token on every clone/fetch, same technique BranchAndCommitService
     * already uses for push.
     */
    private function authArgsFor(RepoConfig $repo): array
    {
        if ($repo->github_connection_id === null) {
            return [];
        }

        $token = $this->githubAuth->tokenFor($repo);

        return ['-c', "http.extraheader=AUTHORIZATION: bearer {$token}"];
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
                'Git command failed: '.implode(' ', $command)."\n".$process->errorOutput()
            );
        }
    }
}
