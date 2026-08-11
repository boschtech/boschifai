<?php

namespace Tests\Unit\Services;

use App\Services\TestExecution\DockerTestRunner;
use App\Services\TestExecution\JunitXmlParser;
use Tests\TestCase;

/**
 * Verified for real against a live Docker socket (see the docker-compose.yml Docker setup —
 * DooD run against a real sibling `rams-app` container). This covers the pure path-translation
 * logic in isolation so a future change can't silently break it without a Docker daemon handy.
 */
class DockerTestRunnerPathTranslationTest extends TestCase
{
    public function test_bare_host_mode_returns_the_path_unchanged_when_no_host_var_path_is_configured(): void
    {
        config(['boschifai.docker.host_var_path' => null]);

        $runner = new DockerTestRunner(new JunitXmlParser());

        $this->assertSame(
            '/var/www/html/var/boschifai/workspaces/abc',
            $runner->dockerVisiblePath('/var/www/html/var/boschifai/workspaces/abc')
        );
    }

    public function test_docker_mode_translates_the_container_path_prefix_to_the_configured_host_path(): void
    {
        config([
            'boschifai.var_path' => '/var/www/html/var/boschifai',
            'boschifai.docker.host_var_path' => '/Users/garthbosch/projects/sinov8/boschifai/var/boschifai',
        ]);

        $runner = new DockerTestRunner(new JunitXmlParser());

        $this->assertSame(
            '/Users/garthbosch/projects/sinov8/boschifai/var/boschifai/workspaces/abc',
            $runner->dockerVisiblePath('/var/www/html/var/boschifai/workspaces/abc')
        );
    }
}
