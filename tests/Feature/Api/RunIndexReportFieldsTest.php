<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Models\CiCheckResult;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reporting section's three list pages (Confidence/Coverage/Test History) all read from the
 * same GET /api/runs list this test covers — RunListResource was extended to carry the fields
 * those pages need rather than adding per-report endpoints, since everything here already lives
 * on Run or its two 1:1 relations.
 */
class RunIndexReportFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_runs_list_exposes_confidence_coverage_and_execution_fields(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/Foo.php',
            'generated_file_path' => 'tests/Feature/FooTest.php',
            'state' => RunState::ReportReady,
            'testability_score' => 90,
            'test_case_verdict' => 'APPROVED',
            'confidence_score' => 94,
            'confidence_breakdown' => ['composite' => 94, 'testability' => 90, 'test_case_quality' => 100, 'local_execution' => 100, 'ci' => 100],
            'pr_url' => 'https://github.com/acme/backend/pull/9',
        ]);

        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 9, 'passed' => 9, 'failed' => 0, 'skipped' => 0,
            'duration_ms' => 506, 'tests' => [],
        ]);

        CiCheckResult::create([
            'run_id' => $run->id, 'workflow_name' => 'CI', 'check_run_id' => 123,
            'conclusion' => 'success', 'polled_at' => now(),
        ]);

        $response = $this->getJson('/api/runs')->assertOk();
        $data = collect($response->json('data'))->firstWhere('id', $run->id);

        $this->assertSame(94, $data['confidence_score']);
        $this->assertSame(94, $data['confidence_breakdown']['composite']);
        $this->assertSame(90, $data['testability_score']);
        $this->assertSame('APPROVED', $data['test_case_verdict']);
        $this->assertSame('tests/Feature/FooTest.php', $data['generated_file_path']);
        $this->assertSame('app/Foo.php', $data['target_file_path']);
        $this->assertSame('https://github.com/acme/backend/pull/9', $data['pr_url']);
        $this->assertSame('success', $data['ci_conclusion']);
        $this->assertSame(9, $data['execution_result']['total']);
        $this->assertSame(9, $data['execution_result']['passed']);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function test_fields_with_no_data_yet_degrade_to_null_rather_than_erroring(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/Foo.php',
            'state' => RunState::Draft,
        ]);

        $response = $this->getJson('/api/runs')->assertOk();
        $data = collect($response->json('data'))->firstWhere('id', $run->id);

        $this->assertNull($data['confidence_breakdown']);
        $this->assertNull($data['generated_file_path']);
        $this->assertNull($data['ci_conclusion']);
        $this->assertNull($data['execution_result']);
    }
}
