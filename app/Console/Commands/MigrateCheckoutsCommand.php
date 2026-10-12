<?php

namespace App\Console\Commands;

use App\Models\RepoConfig;
use App\Services\Sandbox\RepoCheckoutManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * One-off after deploying workspaces: moves GitHub-connected checkouts from the old
 * `repos/{name}` folders to `repos/{workspace_id}/{repo_config_id}`. Skipping it is safe — a
 * missing checkout is simply cloned again on the next run — but moving keeps the clone (and
 * anything a run in progress left in it). Local repos aren't touched: they're used in place.
 */
class MigrateCheckoutsCommand extends Command
{
    protected $signature = 'boschifai:migrate-checkouts';

    protected $description = 'Move repository checkouts to the per-workspace folder layout';

    public function handle(): int
    {
        $moved = 0;

        RepoConfig::whereNotNull('github_connection_id')->orderBy('id')->each(function (RepoConfig $repo) use (&$moved) {
            $old = config('boschifai.var_path').'/repos/'.$repo->name;
            $new = RepoCheckoutManager::checkoutPathFor($repo->workspace_id, $repo->id);

            if (! File::isDirectory($old) || File::exists($new)) {
                return;
            }

            File::ensureDirectoryExists(dirname($new));
            File::moveDirectory($old, $new);
            $this->line("Moved {$repo->display_name}: repos/{$repo->name} → repos/{$repo->workspace_id}/{$repo->id}");
            $moved++;
        });

        $this->info($moved === 0 ? 'Nothing to move.' : "Moved {$moved} checkout(s).");

        return self::SUCCESS;
    }
}
