<?php

namespace App\Services\Sandbox;

use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Creates an isolated `git worktree` per Run off the shared mirror clone, then materializes
 * the Boschifai `.claude/commands`/`.claude/skills` scaffolding via `boschifai init`.
 *
 * IMPORTANT — confirmed by a real spike (see plan §3): `.claude/` is NOT git-tracked in the
 * rams repo. A plain `git worktree add` therefore does NOT carry `/boschifai-review` etc. — running
 * `boschifai init --yes --force` here is not optional, it's the fix for a real failure mode
 * (`Unknown command: /boschifai-review`) observed when this step was skipped.
 */
class WorktreeManager
{
    public function __construct(private RepoMirrorManager $mirrors)
    {
    }

    public function path(Run $run): string
    {
        return config('boschifai.var_path')."/workspaces/{$run->id}";
    }

    /**
     * Idempotent: a retried step (RunController::retryStep) re-enters this method for a Run
     * whose worktree already exists — re-running `git worktree add` on an existing path would
     * fail, so an existing worktree is reused as-is rather than recreated.
     */
    public function create(Run $run): string
    {
        $worktreePath = $this->path($run);

        if (File::isDirectory($worktreePath)) {
            return $worktreePath;
        }

        $repo = $run->repoConfig;
        $mirrorPath = $this->mirrors->ensureFresh($repo);

        File::ensureDirectoryExists(dirname($worktreePath));

        // --detach: confirmed by a real bug caught in an end-to-end smoke test — a second
        // concurrent Run's `git worktree add <path> <base_branch>` fails outright ("'prod' is
        // already checked out at ...") because git refuses to have the same branch checked out
        // in two worktrees at once. Every Run needs its own worktree off the same base branch,
        // so it must be a detached checkout, not a branch checkout. This doesn't affect
        // BranchAndCommitService's later `git checkout -b <branch> <base_branch>` — that names
        // the base ref explicitly and works fine from a detached HEAD.
        $this->run($mirrorPath, [
            'git', 'worktree', 'add', '--detach', $worktreePath, $repo->base_branch,
        ]);

        // Materialize .claude/commands + .claude/skills — see class docblock.
        $this->run($worktreePath, ['boschifai', 'init', '--yes', '--force']);

        $this->copyUntrackedFiles($repo, $worktreePath);

        File::ensureDirectoryExists($worktreePath.'/.boschifai');

        return $worktreePath;
    }

    public function remove(Run $run): void
    {
        $worktreePath = $this->path($run);

        if (! File::isDirectory($worktreePath)) {
            return;
        }

        $mirrorPath = $this->mirrors->mirrorPath($run->repoConfig);

        // --force: the worktree may contain the untracked boschifai-init scaffolding and .boschifai/
        // artifacts, which is expected and fine to discard along with the whole worktree.
        $this->run($mirrorPath, ['git', 'worktree', 'remove', '--force', $worktreePath]);
    }

    /**
     * `git status --porcelain --untracked-files=all` as a plain list of relative paths.
     * Callers snapshot this immediately after create() (baseline includes boschifai-init's own new
     * files — see plan §3's "artifact-diff baseline" note) and again after each Claude
     * invocation, diffing the two to find what that step actually produced — never by parsing
     * a transcript for paths.
     *
     * `--untracked-files=all` is not optional: confirmed by a real bug caught in an end-to-end
     * smoke test — plain `git status --porcelain` collapses an already-untracked directory
     * (`.boschifai/`, created once per run) into a single "?? .boschifai/" line instead of
     * listing files inside it. Since that directory is already untracked in the "before"
     * snapshot (the requirement file is written into it before the first Claude invocation),
     * a new file Claude writes inside it produces the exact same single collapsed line in the
     * "after" snapshot too — the diff silently finds nothing. `--untracked-files=all` forces
     * git to recurse and list individual files, which is what makes the diff actually work.
     */
    public function statusPaths(Run $run): array
    {
        $worktreePath = $this->path($run);
        $process = Process::path($worktreePath)->timeout(30)->run(['git', 'status', '--porcelain', '--untracked-files=all']);

        if ($process->failed()) {
            throw new RuntimeException('git status failed in '.$worktreePath."\n".$process->errorOutput());
        }

        $paths = [];
        foreach (explode("\n", trim($process->output())) as $line) {
            if ($line === '') {
                continue;
            }
            // Porcelain format: "XY path" (XY = 2 status chars, e.g. "??", " M").
            $paths[] = trim(substr($line, 3));
        }

        return $paths;
    }

    /**
     * Copies files listed in RepoConfig.copy_untracked_files from the source checkout
     * (git_remote_path) into the fresh worktree. Confirmed as a real, previously-undiscovered
     * gap via a live Docker end-to-end test: RAMS's `auth.json` (gitignored, holds a GitHub
     * OAuth token for a private Composer package) is invisible to any git clone/worktree, and
     * without it `composer install` fails cloning that package over SSH inside the throwaway
     * `rams-app` container DockerTestRunner spins up. Same root cause as `.claude/` needing
     * `boschifai init` above — untracked files never travel with git — but `boschifai init` can regenerate
     * scaffolding, whereas a credentials file has to be copied from somewhere real.
     */
    private function copyUntrackedFiles(RepoConfig $repo, string $worktreePath): void
    {
        foreach ($repo->copy_untracked_files ?? [] as $relativePath) {
            $source = rtrim($repo->git_remote_path, '/').'/'.$relativePath;
            if (File::exists($source)) {
                File::copy($source, $worktreePath.'/'.$relativePath);
            }
        }
    }

    private function run(string $cwd, array $command): void
    {
        $process = Process::path($cwd)->timeout(120)->run($command);

        if ($process->failed()) {
            throw new RuntimeException(
                'Command failed in '.$cwd.': '.implode(' ', $command)."\n".$process->errorOutput()
            );
        }
    }
}
