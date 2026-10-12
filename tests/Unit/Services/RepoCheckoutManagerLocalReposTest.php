<?php

namespace Tests\Unit\Services;

use App\Models\RepoConfig;
use App\Services\Sandbox\RepoCheckoutManager;
use RuntimeException;
use Tests\TestCase;

class RepoCheckoutManagerLocalReposTest extends TestCase
{
    private function localRepo(): RepoConfig
    {
        return new RepoConfig([
            'name' => 'rams', 'display_name' => 'rams',
            'git_remote_path' => '/host-repos/sinov8/rams', 'base_branch' => 'main',
            'github_connection_id' => null,
        ]);
    }

    public function test_a_local_repo_is_used_in_place_when_local_repos_are_enabled(): void
    {
        config(['boschifai.features.local_repos' => true]);

        $this->assertSame('/host-repos/sinov8/rams', app(RepoCheckoutManager::class)->ensureReady($this->localRepo()));
    }

    public function test_an_already_connected_local_repo_cannot_be_run_when_local_repos_are_disabled(): void
    {
        config(['boschifai.features.local_repos' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Local repositories are disabled on this installation, so 'rams' can't be used.");

        app(RepoCheckoutManager::class)->ensureReady($this->localRepo());
    }
}
