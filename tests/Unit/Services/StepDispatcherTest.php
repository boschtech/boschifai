<?php

namespace Tests\Unit\Services;

use App\Enums\RunState;
use App\Models\Run;
use App\Services\Pipeline\StepDispatcher;
use InvalidArgumentException;
use Tests\TestCase;

class StepDispatcherTest extends TestCase
{
    public function test_each_step_maps_to_its_running_state(): void
    {
        $dispatcher = new StepDispatcher;

        $this->assertSame(RunState::GapAnalysisRunning, $dispatcher->runningStateFor('test_plan'));
        $this->assertSame(RunState::GenerationRunning, $dispatcher->runningStateFor('fix_failing_tests'));
        $this->assertSame(RunState::LocalExecutionRunning, $dispatcher->runningStateFor('local_execution'));
        $this->assertSame(RunState::Pushing, $dispatcher->runningStateFor('push'));
        $this->assertSame(RunState::StandaloneRunning, $dispatcher->runningStateFor('build_knowledge_base'));
    }

    public function test_steps_without_their_own_job_cannot_be_dispatched(): void
    {
        $dispatcher = new StepDispatcher;

        $this->assertFalse($dispatcher->canDispatch('ci_poll'));
        $this->expectException(InvalidArgumentException::class);
        $dispatcher->dispatch(new Run, 'ci_poll');
    }

    public function test_only_claude_steps_need_credits_and_push_only_on_the_mcp_path(): void
    {
        $dispatcher = new StepDispatcher;

        $this->assertTrue($dispatcher->usesClaude('code_generation'));
        $this->assertFalse($dispatcher->usesClaude('local_execution'));
        $this->assertFalse($dispatcher->usesClaude('ci_poll'));

        config(['boschifai.features.github_mcp_push' => true, 'boschifai.github_mcp.pat' => null]);
        $this->assertFalse($dispatcher->usesClaude('push'));
        config(['boschifai.github_mcp.pat' => 'ghp_test']);
        $this->assertTrue($dispatcher->usesClaude('push'));
        config(['boschifai.features.github_mcp_push' => false]);
        $this->assertFalse($dispatcher->usesClaude('push'));
    }
}
