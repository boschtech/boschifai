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
}
