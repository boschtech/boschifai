<?php

namespace App\Services\TestExecution;

use App\Models\Run;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs the newly generated test file, and only that file, inside RAMS's own pre-built
 * `rams-app` image against the run's worktree — before Gate 2, so the human approver sees a
 * real local pass/fail rather than just generated source. No MySQL container is started: RAMS's
 * own phpunit.xml already configures SQLite in-memory, which is exactly why this MVP slice
 * (plan decision #1) was chosen as the fast/no-external-infra path.
 */
class DockerTestRunner
{
    /** Same rationale/values as HeadlessClaudeInvoker — see that class for why these aren't larger/smaller. */
    private const CANCEL_CHECK_INTERVAL_SECONDS = 1.0;

    private const POLL_INTERVAL_MICROSECONDS = 300_000;

    public function __construct(private JunitXmlParser $junitParser)
    {
    }

    /**
     * @param callable(): bool $isCancelled polled while `docker run` executes; on true, the
     *                                       `docker run` client process is signalled to stop —
     *                                       since it's attached (no `-d`), Docker forwards the
     *                                       signal to the container and `--rm` cleans it up.
     * @return array{cancelled: bool, total: int, passed: int, failed: int, skipped: int, duration_ms: int, tests: array, junit_xml_path: ?string}
     */
    public function run(Run $run, string $worktreePath, string $generatedTestRelativePath, callable $isCancelled): array
    {
        $dockerImage = $run->repoConfig->docker_image;
        $junitRelativePath = ".boschifai/junit-{$run->id}.xml";

        // Docker-outside-of-Docker: this process's own `-v` source must be a HOST path, since
        // the `docker run` below is scheduled by the host daemon via the mounted socket, not by
        // whatever container this code happens to be running inside — see config/boschifai.php.
        $mountSource = $this->dockerVisiblePath($worktreePath);

        // Bumped from 300s: confirmed live that a cold composer cache on RAMS's real dependency
        // tree (hundreds of packages, several private git clones) can eat the full 300s during
        // `composer install` alone, before `php artisan test` ever runs — caught via this same
        // step's own new live-output log showing the process killed mid "Generating optimized
        // autoload files". 900s gives real headroom for a cold-cache install plus the actual
        // test run; a warm cache (composer cache is a persistent Docker volume, shared across
        // runs) finishes in well under a minute regardless.
        $timeoutSeconds = 900;
        $started = microtime(true);

        // Same live-tailing technique as HeadlessClaudeInvoker: truncate/create up front, then
        // append raw stdout/stderr bytes as they arrive, so a concurrent request (the activity
        // endpoint) can show the real composer/phpunit output while this is still running,
        // rather than only a static "running..." message until the whole thing finishes.
        $logPath = $this->logPathFor($run->id);
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, '');

        $invoked = Process::timeout($timeoutSeconds)->start([
            'docker', 'run', '--rm',
            '-v', "{$mountSource}:/var/www/html",
            '-v', 'boschifai_composer_cache:/root/.composer/cache',
            '-e', 'APP_ENV=testing',
            $dockerImage,
            'sh', '-c',
            'composer install --no-interaction --no-progress -o'
                .' && php artisan test '.escapeshellarg($generatedTestRelativePath)
                .' --log-junit '.escapeshellarg($junitRelativePath),
        ], function (string $type, string $bytes) use ($logPath) {
            File::append($logPath, $bytes);
        });

        // See HeadlessClaudeInvoker's docblock on the same pattern for why this is tracked
        // manually rather than relying on Symfony Process's own timeout firing from isRunning().
        $deadline = $started + $timeoutSeconds;
        $lastCancelCheck = 0.0;
        $cancelled = false;

        while ($invoked->running()) {
            $now = microtime(true);

            if ($now >= $deadline) {
                $invoked->stop(5);
                break;
            }

            if ($now - $lastCancelCheck >= self::CANCEL_CHECK_INTERVAL_SECONDS) {
                $lastCancelCheck = $now;
                if ($isCancelled()) {
                    $invoked->stop(5);
                    $cancelled = true;
                    break;
                }
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $process = $invoked->wait();

        if ($cancelled) {
            return [
                'cancelled' => true,
                'total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'tests' => [],
                'junit_xml_path' => null,
            ];
        }

        $junitAbsolutePath = $worktreePath.'/'.$junitRelativePath;

        if (! File::exists($junitAbsolutePath)) {
            // The container may have failed before PHPUnit ever ran (composer install failure,
            // a PHP fatal error in the generated file, etc.) — surface the raw output rather
            // than a parse error, since there is no JUnit report to parse.
            throw new RuntimeException(
                "No JUnit report produced for {$generatedTestRelativePath}.\n".$process->output().$process->errorOutput()
            );
        }

        $parsed = $this->junitParser->parse($junitAbsolutePath);
        $parsed['cancelled'] = false;
        $parsed['junit_xml_path'] = $junitRelativePath;

        return $parsed;
    }

    /** Deterministic (no random suffix, unlike HeadlessClaudeInvoker's transcripts) — at most one local_execution runs per Run at a time, so nothing needs to disambiguate concurrent attempts. */
    public function logPathFor(string $runId): string
    {
        return storage_path("app/boschifai-runs/{$runId}/local_execution.log");
    }

    /**
     * Translates this process's own view of a path under `var_path` into the equivalent HOST
     * path, when one is configured (i.e. running inside the Dockerized worker). No-op in
     * bare-host mode, where this process's filesystem view already is the host's.
     *
     * Public for direct unit testing (verified for real against a live Docker socket in
     * addition to this — see the Docker setup notes — but the pure translation logic is worth
     * covering in isolation too).
     */
    public function dockerVisiblePath(string $path): string
    {
        $hostVarPath = config('boschifai.docker.host_var_path');

        if ($hostVarPath === null) {
            return $path;
        }

        return str_replace(config('boschifai.var_path'), $hostVarPath, $path);
    }
}
