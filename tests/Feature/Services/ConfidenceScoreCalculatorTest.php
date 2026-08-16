<?php

namespace Tests\Feature\Services;

use App\Models\CiCheckResult;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use App\Services\Confidence\ConfidenceScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfidenceScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_score_matches_the_documented_weighting(): void
    {
        // Weights per config/boschifai.php and plan §7: testability 25, test_case_quality 20,
        // local_execution 25, ci 30.
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => 'report_ready',
            'testability_score' => 80, // -> 80 * 25 = 2000
            'test_case_verdict' => 'APPROVED', // -> 100 * 20 = 2000
        ]);

        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 10, 'passed' => 8, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 1000,
        ]); // passRate 80% -> 80 * 25 = 2000

        CiCheckResult::create([
            'run_id' => $run->id, 'workflow_name' => 'Sonar Scan', 'conclusion' => 'success',
        ]); // -> 100 * 30 = 3000

        $run->refresh();
        $breakdown = (new ConfidenceScoreCalculator())->calculate($run);

        // (2000 + 2000 + 2000 + 3000) / 100 = 90
        $this->assertSame(90, $breakdown['composite']);
        $this->assertSame(80, $breakdown['testability']);
        $this->assertSame(100, $breakdown['test_case_quality']);
        $this->assertSame(80, $breakdown['local_execution']);
        $this->assertSame(100, $breakdown['ci']);
    }

    public function test_ci_failure_zeroes_that_component_even_with_everything_else_perfect(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => 'report_ready',
            'testability_score' => 100,
            'test_case_verdict' => 'APPROVED',
        ]);

        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 5, 'passed' => 5, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 500,
        ]);

        CiCheckResult::create([
            'run_id' => $run->id, 'workflow_name' => 'Sonar Scan', 'conclusion' => 'failure',
        ]);

        $run->refresh();
        $breakdown = (new ConfidenceScoreCalculator())->calculate($run);

        // (100*25 + 100*20 + 100*25 + 0*30) / 100 = 70 — CI is the highest-weighted component
        // (plan §7 rationale: it's the only fully independent, real-infrastructure signal), so
        // a CI failure should visibly cap the composite even when every self-reported signal
        // is perfect.
        $this->assertSame(70, $breakdown['composite']);
        $this->assertSame(0, $breakdown['ci']);
    }

    public function test_pre_push_renormalizes_weights_over_the_three_available_components(): void
    {
        // At Gate 2 there's no CI result yet (nothing has been pushed) — calculatePrePush()
        // drops the ci component and renormalizes 25/20/25 up to sum-to-100, rather than
        // scoring the missing signal 0 the way calculate() does.
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => 'local_execution_complete',
            'testability_score' => 80,
            'test_case_verdict' => 'APPROVED',
        ]);

        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 10, 'passed' => 8, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 1000,
        ]);

        $run->refresh();
        $breakdown = (new ConfidenceScoreCalculator())->calculatePrePush($run);

        // (80*25 + 100*20 + 80*25) / 70 = 85.71 -> rounds to 86
        $this->assertSame(86, $breakdown['composite']);
        $this->assertSame(80, $breakdown['testability']);
        $this->assertSame(100, $breakdown['test_case_quality']);
        $this->assertSame(80, $breakdown['local_execution']);
        $this->assertArrayNotHasKey('ci', $breakdown);
    }
}
