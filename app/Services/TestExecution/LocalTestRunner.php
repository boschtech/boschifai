<?php

namespace App\Services\TestExecution;

use App\Models\Run;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Runs the newly generated test file, and only that file, directly against the run's repo
 * checkout — before Gate 2, so the human approver sees a real local pass/fail rather than just
 * generated source.
 *
 * Does NOT run the command itself — it writes a job descriptor to a shared directory and polls
 * for the `test-runner` sidecar container's result (see docker-compose.yml's `test-runner`
 * service and docker/test-runner/poll.php for the full protocol). This class used to invoke
 * the command directly as a child process of `worker` — moved out in direct response to a real
 * incident: `worker` carries Boschifai's own live DB credentials as ambient environment (needed
 * for its own queue/app work), and a connected repo's own test suite inherited that environment
 * when its command ran as `worker`'s own child process, then ran `migrate:fresh` against
 * Boschifai's actual database via `RefreshDatabase`. `test-runner` never has those credentials
 * and has no network route to the database, so that class of failure is now structurally
 * impossible rather than dependent on every connected repo's own env-override config being
 * correct.
 *
 * Requirement-mode runs assume every connected repo is PHPUnit/Laravel-shaped (the MVP's own
 * scope) and always use the hardcoded command below; no MySQL — assumes the target repo's own
 * phpunit.xml configures SQLite in-memory, same assumption the Docker-based runner made.
 *
 * Coverage-mode runs pass an execution recipe (`{install_command, test_command}`, parsed out of
 * the codebase-analysis artifact by CodebaseKnowledgeBaseParser) instead, since the target repo
 * can be any stack `/boschifai-gen-component` detects — `test_command`'s own
 * `{{TEST_FILE}}`/`{{JUNIT_PATH}}` placeholders are substituted here rather than baked in by
 * Claude, so this class stays the single place that decides the actual JUnit output path.
 *
 * Either path, once the final command is built, gets an HTML coverage report requested (see
 * COVERAGE_REPORT_DIR) if — and only if — it's shaped like `php artisan test ...`: confirmed
 * live that coverage-mode's own dynamically-authored recipe, against this org's actual
 * PHP/Laravel repos, produces exactly that shape (Claude correctly picks `--log-junit`, not some
 * other stack's flag), so gating on command shape rather than run mode is what makes coverage
 * actually show up for coverage-mode runs too — the only mode this org has run in practice so
 * far. Any other stack's command (pytest, Jest, JUnit, ...) is left untouched.
 */
class LocalTestRunner
{
    /** Same rationale/values as HeadlessClaudeInvoker — see that class for why these aren't larger/smaller. */
    private const CANCEL_CHECK_INTERVAL_SECONDS = 1.0;

    private const POLL_INTERVAL_MICROSECONDS = 300_000;

    /**
     * PHPUnit's `--coverage-html` output directory, relative to the checkout root. Requires a
     * coverage driver (PCOV — see docker/app/Dockerfile) in whatever PHP the test-runner sidecar
     * uses; without one, PHPUnit just warns to stderr and writes nothing here, so
     * coverage_report_path below degrades to null rather than the run failing.
     */
    private const COVERAGE_REPORT_DIR = 'coverage-report';

    public function __construct(private JunitXmlParser $junitParser)
    {
    }

    /**
     * @param ?array{install_command: string, test_command: string} $recipe when present
     *        (coverage mode), replaces the hardcoded composer/phpunit command below.
     * @param callable(): bool $isCancelled polled while the sidecar runs the command; on true,
     *                                       a cancel file is written for the sidecar to notice.
     * @return array{cancelled: bool, total: int, passed: int, failed: int, skipped: int, duration_ms: int, tests: array, junit_xml_path: ?string, coverage_report_path: ?string}
     */
    public function run(Run $run, string $checkoutPath, string $generatedTestRelativePath, callable $isCancelled, ?array $recipe = null): array
    {
        $junitRelativePath = ".boschifai/junit-{$run->id}.xml";

        // Bumped from 300s (its own value when this ran inside Docker): confirmed live that a
        // cold composer cache on a real dependency tree (hundreds of packages, several private
        // git clones) can eat the full 300s during `composer install` alone, before `php artisan
        // test` ever runs. 900s gives real headroom for a cold-cache install plus the actual
        // test run; a warm cache (the `test-runner` sidecar's own ~/.composer/cache, shared
        // across runs) finishes in well under a minute regardless.
        $timeoutSeconds = 900;
        $started = microtime(true);

        $shellCommand = $recipe !== null
            ? $this->buildRecipeCommand($recipe, $generatedTestRelativePath, $junitRelativePath)
            : 'composer install --no-interaction --no-progress -o'
                .' && php artisan test '.escapeshellarg($generatedTestRelativePath)
                .' --log-junit '.escapeshellarg($junitRelativePath);

        // See class docblock — gated on command shape, not run mode, so a coverage-mode recipe
        // that itself turns out to be `php artisan test` (this org's real-world case) gets a
        // coverage report too, not just requirement-mode's hardcoded command.
        $coverageEnabled = str_contains($shellCommand, 'php artisan test');
        if ($coverageEnabled) {
            $shellCommand .= ' --coverage-html '.escapeshellarg(self::COVERAGE_REPORT_DIR);
        }

        $jobsDir = config('boschifai.test_runner.jobs_path');
        File::ensureDirectoryExists($jobsDir);

        $jobPath = "{$jobsDir}/{$run->id}.job.json";
        $donePath = "{$jobsDir}/{$run->id}.done.json";
        $cancelPath = "{$jobsDir}/{$run->id}.cancel";

        // Stale files from an earlier attempt at this same run id (a retry) must not be read as
        // this attempt's result — the sidecar always starts a job from a clean slate.
        if (File::exists($donePath)) {
            File::delete($donePath);
        }
        if (File::exists($cancelPath)) {
            File::delete($cancelPath);
        }
        File::put($this->logPathFor($run->id), '');

        File::put($jobPath, json_encode([
            'cwd' => $checkoutPath,
            'command' => $shellCommand,
            'timeout_seconds' => $timeoutSeconds,
        ]));

        // The sidecar enforces $timeoutSeconds itself and always writes done.json, even on its
        // own timeout — this is only a last-resort ceiling for how long WE wait to hear back,
        // covering the sidecar container itself being unreachable/crashed, not the primary
        // timeout mechanism (mirrors HeadlessClaudeInvoker's own manual-deadline docblock).
        $waitDeadline = $started + $timeoutSeconds + 60;
        $lastCancelCheck = 0.0;
        $cancelRequested = false;

        while (! File::exists($donePath)) {
            $now = microtime(true);

            if (! $cancelRequested && $now - $lastCancelCheck >= self::CANCEL_CHECK_INTERVAL_SECONDS) {
                $lastCancelCheck = $now;
                if ($isCancelled()) {
                    File::put($cancelPath, '1');
                    $cancelRequested = true;
                }
            }

            if ($now >= $waitDeadline) {
                throw new RuntimeException(
                    "The test-runner sidecar did not report back for run {$run->id} within the expected time — it may be unreachable or crashed."
                );
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $done = json_decode(File::get($donePath), true);

        if ($done['cancelled'] ?? false) {
            return [
                'cancelled' => true,
                'total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'tests' => [],
                'junit_xml_path' => null,
                'coverage_report_path' => null,
            ];
        }

        $junitAbsolutePath = $checkoutPath.'/'.$junitRelativePath;

        if (! File::exists($junitAbsolutePath)) {
            // The command may have failed before PHPUnit ever ran (composer install failure,
            // a PHP fatal error in the generated file, etc.) — surface the sidecar's captured
            // output rather than a parse error, since there is no JUnit report to parse.
            $log = File::exists($this->logPathFor($run->id)) ? File::get($this->logPathFor($run->id)) : '';
            throw new RuntimeException("No JUnit report produced for {$generatedTestRelativePath}.\n".$log);
        }

        $parsed = $this->junitParser->parse($junitAbsolutePath);
        $parsed['cancelled'] = false;
        $parsed['junit_xml_path'] = $junitRelativePath;
        $parsed['coverage_report_path'] = $coverageEnabled
            ? $this->coverageReportPathFor($checkoutPath, $run->target_file_path)
            : null;

        return $parsed;
    }

    /**
     * PHPUnit's HTML coverage report mirrors the source tree under COVERAGE_REPORT_DIR, one page
     * per covered file named `<original filename>.html` (e.g.
     * `app/Http/Controllers/FooController.php` → `coverage-report/app/Http/Controllers/FooController.php.html`).
     * Returns the page path relative to COVERAGE_REPORT_DIR itself (what RunController's own
     * coverage-report route expects), or null if no report was produced for this class — e.g. no
     * coverage driver available in this environment (see this class's own const doc), or
     * $targetFilePath falls outside phpunit.xml's configured `<source><include>` paths.
     */
    private function coverageReportPathFor(string $checkoutPath, string $targetFilePath): ?string
    {
        $relativePath = $targetFilePath.'.html';
        $absolutePath = $checkoutPath.'/'.self::COVERAGE_REPORT_DIR.'/'.$relativePath;

        return File::exists($absolutePath) ? $relativePath : null;
    }

    /** @param array{install_command: string, test_command: string} $recipe */
    private function buildRecipeCommand(array $recipe, string $generatedTestRelativePath, string $junitRelativePath): string
    {
        $testCommand = str_replace(
            ['{{TEST_FILE}}', '{{JUNIT_PATH}}'],
            [escapeshellarg($generatedTestRelativePath), escapeshellarg($junitRelativePath)],
            $recipe['test_command'],
        );

        return $recipe['install_command'].' && '.$testCommand;
    }

    /**
     * Deterministic (no random suffix, unlike HeadlessClaudeInvoker's transcripts) — at most one
     * local_execution runs per Run at a time, so nothing needs to disambiguate concurrent
     * attempts. Lives on the shared jobs volume (written by the `test-runner` sidecar), not
     * local storage — both `worker` (this class) and `app` (RunController::activity's live
     * tailing) need to reach the same path, and only the sidecar actually has the running
     * process to stream output from.
     */
    public function logPathFor(string $runId): string
    {
        return config('boschifai.test_runner.jobs_path')."/{$runId}.log";
    }
}
