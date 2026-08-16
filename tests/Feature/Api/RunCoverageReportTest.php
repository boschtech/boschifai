<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Serves PHPUnit's --coverage-html output from inside a run's own checkout — see
 * LocalTestRunner's own docblock for why this report can't just be a public storage URL.
 */
class RunCoverageReportTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-coverage-report-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath.'/coverage-report/app/Http/Controllers');
        File::put(
            $this->checkoutPath.'/coverage-report/app/Http/Controllers/BillingController.php.html',
            '<html><body>coverage</body></html>'
        );
        File::ensureDirectoryExists($this->checkoutPath.'/.env-not-part-of-the-report');
        File::put($this->checkoutPath.'/.env', 'SECRET=do-not-serve-this');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/Http/Controllers/BillingController.php',
            'state' => RunState::ReportReady,
        ]);
    }

    public function test_serves_the_class_specific_coverage_page(): void
    {
        $run = $this->makeRun();

        $response = $this->get('/api/runs/'.$run->id.'/coverage-report/app/Http/Controllers/BillingController.php.html');

        $response->assertOk();
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('coverage', $response->getContent());
    }

    public function test_a_traversal_attempt_outside_the_coverage_report_directory_404s(): void
    {
        $run = $this->makeRun();

        $response = $this->get('/api/runs/'.$run->id.'/coverage-report/../../.env');

        $response->assertNotFound();
    }

    public function test_a_nonexistent_page_within_the_report_404s(): void
    {
        $run = $this->makeRun();

        $response = $this->get('/api/runs/'.$run->id.'/coverage-report/app/Http/Controllers/DoesNotExist.php.html');

        $response->assertNotFound();
    }

    public function test_404s_when_no_coverage_report_was_ever_generated(): void
    {
        File::deleteDirectory($this->checkoutPath.'/coverage-report');
        $run = $this->makeRun();

        $response = $this->get('/api/runs/'.$run->id.'/coverage-report/anything.html');

        $response->assertNotFound();
    }
}
