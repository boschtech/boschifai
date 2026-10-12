<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\ClaudeInvocation;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UsageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStep(string $requirement = "Add login throttling\nMore detail here"): RunStep
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => RunType::Requirement,
            'requirement_text' => $requirement,
            'target_file_path' => 'app/Foo.php',
            'state' => RunState::Draft,
        ]);

        return RunStep::create([
            'run_id' => $run->id,
            'key' => 'gap_analysis',
            'status' => RunStepStatus::Succeeded,
        ]);
    }

    private function invocation(RunStep $step, array $attributes, ?string $createdAt = null): ClaudeInvocation
    {
        $invocation = ClaudeInvocation::create(array_merge([
            'run_step_id' => $step->id,
            'command' => '/boschifai-review',
            'prompt_text' => 'prompt',
            'exit_code' => 0,
        ], $attributes));

        if ($createdAt !== null) {
            $invocation->forceFill(['created_at' => $createdAt])->save();
        }

        return $invocation;
    }

    public function test_tokens_this_month_is_all_zero_with_no_invocations(): void
    {
        $this->getJson('/api/usage/tokens-this-month')
            ->assertOk()
            ->assertExactJson([
                'month' => '2026-10',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'total_tokens' => 0,
                'total_cost_usd' => 0,
            ]);
    }

    public function test_tokens_this_month_sums_only_the_current_calendar_month_including_cache_tokens(): void
    {
        $step = $this->makeStep();

        $this->invocation($step, [
            'input_tokens' => 100, 'output_tokens' => 50,
            'cache_creation_input_tokens' => 10, 'cache_read_input_tokens' => 5,
            'total_cost_usd' => 0.12345,
        ]);
        $this->invocation($step, [
            'input_tokens' => 200, 'output_tokens' => 25,
            'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 20,
            'total_cost_usd' => 0.5,
        ]);
        // Last month — must be excluded.
        $this->invocation($step, [
            'input_tokens' => 9999, 'output_tokens' => 9999, 'total_cost_usd' => 9,
        ], '2026-09-30 23:59:00');

        $response = $this->getJson('/api/usage/tokens-this-month')->assertOk();

        $this->assertSame('2026-10', $response->json('month'));
        $this->assertSame(300, $response->json('input_tokens'));
        $this->assertSame(75, $response->json('output_tokens'));
        $this->assertSame(10, $response->json('cache_creation_input_tokens'));
        $this->assertSame(25, $response->json('cache_read_input_tokens'));
        $this->assertSame(410, $response->json('total_tokens'));
        $this->assertEquals(0.6235, $response->json('total_cost_usd'));
    }

    public function test_details_lists_each_invocation_with_run_context_newest_first(): void
    {
        $step = $this->makeStep("  Add login throttling  \nSecond line should not appear");

        $older = $this->invocation($step, [
            'input_tokens' => 1, 'output_tokens' => 2,
            'cache_creation_input_tokens' => 3, 'cache_read_input_tokens' => 4,
            'total_cost_usd' => 0.01, 'num_turns' => 3, 'duration_ms' => 1500,
            'stop_reason' => 'end_turn', 'timed_out' => false,
        ], '2026-10-02 10:00:00');
        $newer = $this->invocation($step, [
            'command' => '/boschifai-test-plan',
            'input_tokens' => 10, 'output_tokens' => 20,
            'total_cost_usd' => null, 'timed_out' => true,
        ], '2026-10-10 10:00:00');
        $this->invocation($step, ['input_tokens' => 5000], '2026-11-01 00:00:01');

        $response = $this->getJson('/api/usage/tokens-this-month/details')->assertOk();

        $this->assertSame(11, $response->json('summary.input_tokens'));
        $this->assertSame(40, $response->json('summary.total_tokens'));

        $rows = $response->json('invocations');
        $this->assertCount(2, $rows);
        $this->assertSame([$newer->id, $older->id], array_column($rows, 'id'));

        $this->assertSame('/boschifai-test-plan', $rows[0]['command']);
        $this->assertNull($rows[0]['total_cost_usd']);
        $this->assertTrue($rows[0]['timed_out']);
        $this->assertSame(30, $rows[0]['total_tokens']);

        $this->assertSame($step->run_id, $rows[1]['run_id']);
        $this->assertSame('acme/backend', $rows[1]['repo_name']);
        $this->assertSame('Add login throttling', $rows[1]['requirement_summary']);
        $this->assertSame('gap_analysis', $rows[1]['step_key']);
        $this->assertSame(10, $rows[1]['total_tokens']);
        $this->assertEquals(0.01, $rows[1]['total_cost_usd']);
        $this->assertSame(3, $rows[1]['num_turns']);
        $this->assertSame(1500, $rows[1]['duration_ms']);
        $this->assertSame('end_turn', $rows[1]['stop_reason']);
        $this->assertFalse($rows[1]['timed_out']);
    }

    public function test_details_truncates_long_requirement_summaries_to_eighty_characters(): void
    {
        $step = $this->makeStep(str_repeat('a', 200));
        $this->invocation($step, ['input_tokens' => 1]);

        $summary = $this->getJson('/api/usage/tokens-this-month/details')
            ->assertOk()
            ->json('invocations.0.requirement_summary');

        $this->assertSame(str_repeat('a', 80).'...', $summary);
    }
}
