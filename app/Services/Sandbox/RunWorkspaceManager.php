<?php

namespace App\Services\Sandbox;

use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Prepares a Run's repo checkout (via RepoCheckoutManager) and materializes the Boschifai
 * `.claude/commands`/`.claude/skills` scaffolding via `boschifai init` — every Run against the
 * same RepoConfig shares this one checkout, so `prepare()` is safe (and necessary) to call again
 * on a retried step; `boschifai init --force` is idempotent either way.
 *
 * IMPORTANT — confirmed by a real spike (see plan §3): `.claude/` is NOT git-tracked in the
 * rams repo. A plain checkout therefore does NOT carry `/boschifai-review` etc. — running
 * `boschifai init --yes --force` here is not optional, it's the fix for a real failure mode
 * (`Unknown command: /boschifai-review`) observed when this step was skipped.
 */
class RunWorkspaceManager
{
    public function __construct(private RepoCheckoutManager $checkouts)
    {
    }

    public function path(Run $run): string
    {
        return $this->checkouts->path($run->repoConfig);
    }

    public function prepare(Run $run): string
    {
        $repo = $run->repoConfig;
        $path = $this->checkouts->ensureReady($repo);

        // `boschifai init --force` OVERWRITES files the current manifest still lists, but never
        // DELETES ones it no longer does — confirmed as a real bug live: renaming
        // boschifai-gen-component-php-laravel to boschifai-gen-feature-php-laravel left the old
        // directory sitting in this checkout from an earlier run, and Claude picked that stale
        // copy (with the pre-rename it_/test_ naming convention) back up on a later step against
        // the SAME already-initialized checkout. These three directories are entirely
        // Boschifai-owned scaffolding per manifest.yaml — never user-authored — so wiping them
        // before every init is safe and keeps each run starting from exactly the current
        // template set, not an ever-accumulating stack of past ones.
        foreach (['.claude/commands', '.claude/skills', '.github/prompts'] as $scaffoldDir) {
            File::deleteDirectory($path.'/'.$scaffoldDir);
        }

        // Materialize .claude/commands + .claude/skills — see class docblock.
        $this->run($path, ['boschifai', 'init', '--yes', '--force']);

        $this->copyUntrackedFiles($repo, $path);

        File::ensureDirectoryExists($path.'/.boschifai');

        return $path;
    }

    /**
     * `git status --porcelain --untracked-files=all` as a plain list of relative paths.
     * Callers snapshot this immediately after prepare() (baseline includes boschifai-init's own
     * new files — see plan §3's "artifact-diff baseline" note) and again after each Claude
     * invocation, diffing the two to find what that step actually produced — never by parsing
     * a transcript for paths.
     *
     * `--untracked-files=all` is not optional: confirmed by a real bug caught in an end-to-end
     * smoke test — plain `git status --porcelain` collapses an already-untracked directory
     * (`.boschifai/`, created once per run) into a single "?? .boschifai/" line instead of
     * listing files inside it. `--untracked-files=all` forces git to recurse and list individual
     * files, which is what makes the diff actually work.
     */
    public function statusPaths(Run $run): array
    {
        $path = $this->path($run);
        // Was 30s — confirmed too low via a real timeout against the actual `rams` checkout
        // (bind-mounted from the host into Docker, which makes a full-tree `git status` scan
        // dramatically slower than on a native filesystem): a real run measured 72s for this
        // exact command on that checkout. 120s gives real headroom without being anywhere close
        // to a Claude step's own multi-hundred-second timeout.
        $process = Process::path($path)->timeout(120)->run(['git', 'status', '--porcelain', '--untracked-files=all']);

        if ($process->failed()) {
            throw new RuntimeException('git status failed in '.$path."\n".$process->errorOutput());
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
     * (git_remote_path) into the run's checkout. Only meaningful for GitHub-connected repos —
     * a local repo's checkout IS git_remote_path, so there's nothing to copy onto itself.
     * Confirmed as a real, previously-undiscovered gap via a live Docker end-to-end test: RAMS's
     * `auth.json` (gitignored, holds a GitHub OAuth token for a private Composer package) is
     * invisible to a fresh checkout, and without it `composer install` fails cloning that
     * package over SSH. Same root cause as `.claude/` needing `boschifai init` above —
     * untracked files never travel with git — but `boschifai init` can regenerate scaffolding,
     * whereas a credentials file has to be copied from somewhere real.
     */
    private function copyUntrackedFiles(RepoConfig $repo, string $checkoutPath): void
    {
        if ($repo->git_remote_path === $checkoutPath) {
            return;
        }

        foreach ($repo->copy_untracked_files ?? [] as $relativePath) {
            $source = rtrim($repo->git_remote_path, '/').'/'.$relativePath;
            if (File::exists($source)) {
                File::copy($source, $checkoutPath.'/'.$relativePath);
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
