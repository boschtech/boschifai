<?php

namespace Tests\Unit\Services;

use App\Enums\RunState;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\TestExecution\JunitXmlParser;
use App\Services\TestExecution\LocalTestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LocalTestRunner no longer runs composer/phpunit itself — it writes a job descriptor to a
 * shared directory and polls for the `test-runner` sidecar container's result (see that class's
 * own docblock and docker/test-runner/poll.php for the full protocol and the real incident that
 * motivated moving execution out of `worker`). A real sidecar container isn't available in the
 * test environment, so these tests point `boschifai.test_runner.jobs_path` at a throwaway temp
 * directory and simulate the sidecar's response with a short, real background shell command —
 * run()'s poll loop (checking every 300ms) picks up the resulting done.json exactly as it would
 * a real sidecar's, without needing Docker in the test run.
 */
class LocalTestRunnerRecipeTest extends TestCase
{
    use RefreshDatabase;

    private string $jobsDir;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobsDir = sys_get_temp_dir().'/boschifai-jobs-test-'.uniqid();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-checkout-test-'.uniqid();
        File::ensureDirectoryExists($this->jobsDir);
        File::ensureDirectoryExists($this->checkoutPath.'/.boschifai');
        config(['boschifai.test_runner.jobs_path' => $this->jobsDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->jobsDir);
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'src/billing.py',
            'state' => RunState::LocalExecutionRunning,
        ]);
    }

    /**
     * Stands in for the test-runner sidecar: after a short real delay, optionally writes a
     * JUnit report at the path the real sidecar's command would have produced, then writes the
     * done.json result the sidecar always writes last.
     */
    private function simulateSidecar(Run $run, bool $cancelled = false, ?string $junitXml = null): void
    {
        if ($junitXml !== null) {
            File::put("{$this->checkoutPath}/.boschifai/junit-{$run->id}.xml", $junitXml);
        }

        $doneContents = json_encode(['exit_code' => 0, 'cancelled' => $cancelled, 'timed_out' => false]);
        $donePath = "{$this->jobsDir}/{$run->id}.done.json";

        // printf '%s' <content> (not `printf <content>`) so a literal `%` in the content is
        // never misread as a printf format specifier.
        $script = sprintf(
            'sleep 0.1 && printf %s %s > %s',
            escapeshellarg('%s'), escapeshellarg($doneContents), escapeshellarg($donePath)
        );
        exec('sh -c '.escapeshellarg($script).' > /dev/null 2>&1 &');
    }

    private function jobCommandFor(Run $run): string
    {
        $job = json_decode(File::get("{$this->jobsDir}/{$run->id}.job.json"), true);

        return $job['command'];
    }

    public function test_recipe_placeholders_are_substituted_into_the_job_command(): void
    {
        $run = $this->makeRun();
        $junitXml = <<<XML
        <?xml version="1.0"?>
        <testsuites>
            <testsuite>
                <testcase classname="BillingTest" name="test_it_calculates_refunds" time="0.01"/>
            </testsuite>
        </testsuites>
        XML;
        $this->simulateSidecar($run, junitXml: $junitXml);

        $runner = new LocalTestRunner(new JunitXmlParser());
        $recipe = [
            'install_command' => 'pip install -r requirements.txt',
            'test_command' => 'pytest {{TEST_FILE}} --junitxml={{JUNIT_PATH}}',
        ];

        $result = $runner->run($run, $this->checkoutPath, 'tests/test_billing.py', fn () => false, $recipe);

        $expectedJunitRelative = ".boschifai/junit-{$run->id}.xml";
        $this->assertSame(
            'pip install -r requirements.txt && pytest '
                .escapeshellarg('tests/test_billing.py')
                .' --junitxml='.escapeshellarg($expectedJunitRelative),
            $this->jobCommandFor($run)
        );

        $this->assertFalse($result['cancelled']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['passed']);
    }

    public function test_no_recipe_falls_back_to_the_hardcoded_composer_phpunit_command(): void
    {
        $run = $this->makeRun();
        $this->simulateSidecar($run, junitXml: <<<XML
        <?xml version="1.0"?>
        <testsuites><testsuite></testsuite></testsuites>
        XML);

        $runner = new LocalTestRunner(new JunitXmlParser());
        $runner->run($run, $this->checkoutPath, 'tests/Feature/BillingTest.php', fn () => false);

        $expectedJunitRelative = ".boschifai/junit-{$run->id}.xml";
        $this->assertSame(
            'composer install --no-interaction --no-progress -o'
                .' && php artisan test '.escapeshellarg('tests/Feature/BillingTest.php')
                .' --log-junit '.escapeshellarg($expectedJunitRelative)
                .' --coverage-html '.escapeshellarg('coverage-report'),
            $this->jobCommandFor($run)
        );
    }

    public function test_coverage_report_path_is_set_when_phpunit_produced_a_report_for_the_target_class(): void
    {
        $run = $this->makeRun(); // target_file_path defaults to 'src/billing.py' in makeRun()
        $run->update(['target_file_path' => 'app/Http/Controllers/BillingController.php']);
        $this->simulateSidecar($run, junitXml: <<<XML
        <?xml version="1.0"?>
        <testsuites><testsuite></testsuite></testsuites>
        XML);
        File::ensureDirectoryExists("{$this->checkoutPath}/coverage-report/app/Http/Controllers");
        File::put("{$this->checkoutPath}/coverage-report/app/Http/Controllers/BillingController.php.html", '<html></html>');

        $runner = new LocalTestRunner(new JunitXmlParser());
        $result = $runner->run($run, $this->checkoutPath, 'tests/Feature/BillingControllerTest.php', fn () => false);

        $this->assertSame('app/Http/Controllers/BillingController.php.html', $result['coverage_report_path']);
    }

    public function test_coverage_report_path_is_null_when_no_report_was_produced(): void
    {
        $run = $this->makeRun();
        $run->update(['target_file_path' => 'app/Http/Controllers/BillingController.php']);
        $this->simulateSidecar($run, junitXml: <<<XML
        <?xml version="1.0"?>
        <testsuites><testsuite></testsuite></testsuites>
        XML);
        // No coverage-report/ directory written at all — e.g. no coverage driver available.

        $runner = new LocalTestRunner(new JunitXmlParser());
        $result = $runner->run($run, $this->checkoutPath, 'tests/Feature/BillingControllerTest.php', fn () => false);

        $this->assertNull($result['coverage_report_path']);
    }

    public function test_coverage_report_path_is_null_for_a_non_php_recipe_based_run(): void
    {
        $run = $this->makeRun();
        $run->update(['target_file_path' => 'app/Http/Controllers/BillingController.php']);
        $this->simulateSidecar($run, junitXml: <<<XML
        <?xml version="1.0"?>
        <testsuites><testsuite></testsuite></testsuites>
        XML);
        // A report happens to exist at the exact path a php-artisan-test-shaped command would
        // check — a pytest-based recipe must never surface it, since --coverage-html is a
        // PHPUnit flag this command never even asked for.
        File::ensureDirectoryExists("{$this->checkoutPath}/coverage-report/app/Http/Controllers");
        File::put("{$this->checkoutPath}/coverage-report/app/Http/Controllers/BillingController.php.html", '<html></html>');

        $runner = new LocalTestRunner(new JunitXmlParser());
        $recipe = ['install_command' => 'true', 'test_command' => 'pytest {{TEST_FILE}} --junitxml={{JUNIT_PATH}}'];
        $result = $runner->run($run, $this->checkoutPath, 'tests/test_billing.py', fn () => false, $recipe);

        $this->assertNull($result['coverage_report_path']);
    }

    /**
     * Regression coverage for a real bug caught live: coverage-mode's own dynamically-authored
     * recipe, when it targets a real PHP/Laravel repo (confirmed as this org's actual real-world
     * case — every live run so far has been coverage mode, never requirement mode), produces its
     * own `php artisan test ...` command. Coverage was previously gated on "recipe is null" (i.e.
     * requirement mode only), which meant it silently never applied to any run this org actually
     * does. Gating on command shape instead fixes that without assuming anything about run mode.
     */
    public function test_coverage_report_path_is_set_for_a_php_artisan_test_shaped_recipe(): void
    {
        $run = $this->makeRun();
        $run->update(['target_file_path' => 'app/Http/Controllers/BillingController.php']);
        $this->simulateSidecar($run, junitXml: <<<XML
        <?xml version="1.0"?>
        <testsuites><testsuite></testsuite></testsuites>
        XML);
        File::ensureDirectoryExists("{$this->checkoutPath}/coverage-report/app/Http/Controllers");
        File::put("{$this->checkoutPath}/coverage-report/app/Http/Controllers/BillingController.php.html", '<html></html>');

        $runner = new LocalTestRunner(new JunitXmlParser());
        $recipe = ['install_command' => 'composer install', 'test_command' => 'php artisan test {{TEST_FILE}} --log-junit {{JUNIT_PATH}}'];
        $result = $runner->run($run, $this->checkoutPath, 'tests/Feature/BillingControllerTest.php', fn () => false, $recipe);

        $this->assertSame('app/Http/Controllers/BillingController.php.html', $result['coverage_report_path']);
        $this->assertStringContainsString('--coverage-html '.escapeshellarg('coverage-report'), $this->jobCommandFor($run));
    }

    public function test_cancellation_writes_a_cancel_file_and_returns_cancelled_once_the_sidecar_reports_it(): void
    {
        $run = $this->makeRun();
        $this->simulateSidecar($run, cancelled: true);

        $runner = new LocalTestRunner(new JunitXmlParser());
        $result = $runner->run($run, $this->checkoutPath, 'tests/Feature/BillingTest.php', fn () => true);

        $this->assertTrue($result['cancelled']);
        $this->assertFileExists("{$this->jobsDir}/{$run->id}.cancel");
    }

    public function test_log_path_lives_on_the_shared_jobs_volume_not_local_storage(): void
    {
        $run = $this->makeRun();
        $runner = new LocalTestRunner(new JunitXmlParser());

        $this->assertSame("{$this->jobsDir}/{$run->id}.log", $runner->logPathFor($run->id));
    }
}
