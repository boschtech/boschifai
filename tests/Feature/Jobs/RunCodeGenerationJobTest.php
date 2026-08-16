<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunType;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunLocalTestExecutionJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression coverage for a real bug caught live: a repo's shared, persistent checkout can carry
 * a test file left over from an earlier run against the same target — already untracked before
 * this step even starts. Editing that file's content doesn't change its `git status` line at
 * all, so it never looks "new" to GitStatusDiffCollector even though Claude did exactly the
 * right thing, and the run failed with "No generated test file was detected" despite a correct
 * edit having actually happened. RunCodeGenerationJob now falls back to a GENERATED_TEST_FILE:
 * sentinel line in the transcript when the git-status diff finds nothing new.
 *
 * Every test here fakes `git status` to always return empty output — meaning
 * GitStatusDiffCollector::newPaths() always finds nothing "new", exactly the condition that
 * triggers the fallback — so each test is really only exercising that fallback's own logic, not
 * re-testing the pre-existing git-diff detection path.
 */
class RunCodeGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-code-gen-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(string $targetFilePath = 'app/Foo.php'): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => RunType::Coverage,
            'requirement_text' => 'x',
            'target_file_path' => $targetFilePath,
            'state' => RunState::GapAnalysisReady,
        ]);
    }

    private function fakeProcesses(string $claudeOutput): void
    {
        Process::fake(function ($process) use ($claudeOutput) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return Process::result(output: $claudeOutput);
            }

            // git status/etc — always empty, so nothing ever looks "new" to the diff collector.
            return Process::result();
        });
    }

    private function assistantLine(string $text): string
    {
        return json_encode([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => $text]]],
        ])."\n";
    }

    public function test_falls_back_to_the_sentinel_path_when_no_new_file_is_detected(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun();

        File::ensureDirectoryExists($this->checkoutPath.'/tests/Feature');
        File::put($this->checkoutPath.'/tests/Feature/FooTest.php', "<?php // already existed, just edited\n");

        $this->fakeProcesses($this->assistantLine(
            "Edited the existing test file.\nGENERATED_TEST_FILE: tests/Feature/FooTest.php"
        ));

        app()->call([new RunCodeGenerationJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::LocalExecutionRunning, $run->state);
        $this->assertSame('tests/Feature/FooTest.php', $run->generated_file_path);
        Queue::assertPushed(RunLocalTestExecutionJob::class);
    }

    public function test_fails_when_the_sentinel_path_does_not_look_like_a_generated_test(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun();

        // Points at the source file itself, not a test — must not be trusted even though it
        // exists on disk.
        $this->fakeProcesses($this->assistantLine('GENERATED_TEST_FILE: app/Foo.php'));

        app()->call([new RunCodeGenerationJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('code_generation', $run->failed_step);
        $this->assertStringContainsString('No generated test file was detected', $run->error_message);
        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }

    public function test_fails_when_the_sentinel_path_does_not_exist_on_disk(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun();

        // A plausible test-shaped path, but never actually written to the checkout.
        $this->fakeProcesses($this->assistantLine('GENERATED_TEST_FILE: tests/Feature/NeverWrittenTest.php'));

        app()->call([new RunCodeGenerationJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('code_generation', $run->failed_step);
        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }

    public function test_fails_when_there_is_no_sentinel_at_all(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun();

        $this->fakeProcesses($this->assistantLine('I could not find anything to test.'));

        app()->call([new RunCodeGenerationJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('code_generation', $run->failed_step);
        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }

    /**
     * Regression coverage for a real bug caught live in the push step (see
     * HeadlessClaudeInvoker's own docblock): `--allowedTools` pre-approving the constrained MCP
     * push tools must only ever be added for the 'push' step. A GitHub MCP PAT is configured
     * globally (every step gets `--mcp-config`), so this confirms code_generation's invocation —
     * which is meant to stay fully agentic per the original MCP design decision — never picks up
     * that push-only allowlist just because a PAT happens to be configured.
     */
    public function test_does_not_scope_allowed_tools_to_the_push_step_list_even_with_a_pat_configured(): void
    {
        config(['boschifai.github_mcp.pat' => 'fake-pat']);
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun();

        File::ensureDirectoryExists($this->checkoutPath.'/tests/Feature');
        File::put($this->checkoutPath.'/tests/Feature/FooTest.php', "<?php // generated\n");

        $this->fakeProcesses($this->assistantLine(
            "Wrote the test file.\nGENERATED_TEST_FILE: tests/Feature/FooTest.php"
        ));

        app()->call([new RunCodeGenerationJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::LocalExecutionRunning, $run->state);

        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command)
                && ($command[0] ?? null) === 'claude'
                && in_array('--mcp-config', $command, true)
                && ! in_array('--allowedTools', $command, true);
        });
    }
}
