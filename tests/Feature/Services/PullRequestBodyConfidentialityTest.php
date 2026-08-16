<?php

namespace Tests\Feature\Services;

use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Github\GithubOAuthService;
use App\Services\Github\PullRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan §7/§8: PR bodies must link to the run, never quote the requirement text — POPIA and
 * general confidentiality policy, same rationale boschifai-github-conventions applies to PR bodies.
 * A future change that carelessly interpolates $run->requirement_text into the PR body would
 * be a real data-exposure regression this test is designed to catch.
 */
class PullRequestBodyConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr_body_never_contains_the_raw_requirement_text(): void
    {
        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $secretLookingRequirementText = 'Tenant SA ID 8501015800080 must be validated on create for Jane Doe.';

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => $secretLookingRequirementText,
            'target_file_path' => 'app/Http/Controllers/CustomFieldController.php',
            'state' => 'local_execution_complete',
            'testability_score' => 80,
            'test_case_verdict' => 'APPROVED',
            'generated_file_path' => 'tests/Feature/CustomFieldControllerTest.php',
        ]);

        $body = (new PullRequestService(new GithubOAuthService()))->buildBody($run);

        $this->assertStringNotContainsString($secretLookingRequirementText, $body);
        $this->assertStringNotContainsString('8501015800080', $body);
        $this->assertStringContainsString((string) $run->id, $body);
    }

    /**
     * Regression coverage for a real bug caught live: the PR body used to unconditionally claim
     * "Adds PHPUnit Feature test coverage... following boschifai-gen-feature-php-laravel
     * conventions" regardless of what the run actually targeted — surfaced when a coverage-mode
     * run against a TypeScript/Vitest repo opened a PR describing itself as PHPUnit. This service
     * has no reliable signal for which stack/skill a given run actually used, so the body must
     * make no claim about either rather than asserting a specific (possibly wrong) one.
     */
    public function test_pr_body_makes_no_stack_specific_claim(): void
    {
        $repo = RepoConfig::create([
            'name' => 'ui-playwright-automation', 'display_name' => 'boschtech/ui-playwright-automation',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'Scan this repository and identify gaps.',
            'target_file_path' => 'ai/modules/cicd-optimizer.ts',
            'state' => 'local_execution_complete',
            'testability_score' => 90,
            'generated_file_path' => 'ai/__tests__/modules/cicd-optimizer.test.ts',
        ]);

        $body = (new PullRequestService(new GithubOAuthService()))->buildBody($run);

        $this->assertStringNotContainsString('PHPUnit', $body);
        $this->assertStringNotContainsString('boschifai-gen-feature-php-laravel', $body);
        $this->assertStringContainsString('ai/__tests__/modules/cicd-optimizer.test.ts', $body);

        // No validator verdict recorded (coverage mode has no separate validator sub-step) — the
        // bullet must be omitted entirely rather than rendered with nothing after the colon.
        $this->assertStringNotContainsString('Test-case validator verdict:', $body);
    }
}
