<?php

namespace Tests\Unit\Services;

use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Sandbox\RepoCheckoutManager;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class RunWorkspaceManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = sys_get_temp_dir().'/boschifai-workspace-'.uniqid();
        File::ensureDirectoryExists($this->checkout);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);
        parent::tearDown();
    }

    private function prepare(): string
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkout, 'base_branch' => 'main',
        ]);
        $run = Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => '']);

        $checkouts = Mockery::mock(RepoCheckoutManager::class);
        $checkouts->shouldReceive('ensureReady')->andReturn($this->checkout);

        return (new RunWorkspaceManager($checkouts))->prepare($run);
    }

    public function test_installs_only_the_pipeline_profile_of_boschifai_templates(): void
    {
        Process::fake();

        $this->assertSame($this->checkout, $this->prepare());

        // Only the commands/skills the pipeline uses, plus the repo's own stack skills (the CLI
        // detects the stack) — never every template, which offered Claude other stacks' skills.
        Process::assertRan(fn ($process) => $process->command === ['boschifai', 'init', '--yes', '--force', '--profile', 'pipeline']
            && $process->path === $this->checkout);
        $this->assertDirectoryExists($this->checkout.'/.boschifai');
    }

    public function test_clears_previous_scaffolding_before_installing(): void
    {
        Process::fake();
        foreach (['.claude/commands', '.claude/skills/boschifai-gen-storybook', '.github/prompts'] as $dir) {
            File::ensureDirectoryExists($this->checkout.'/'.$dir);
            File::put($this->checkout.'/'.$dir.'/stale.md', 'from an earlier full install');
        }
        File::ensureDirectoryExists($this->checkout.'/app');
        File::put($this->checkout.'/app/Keep.php', '<?php');

        $this->prepare();

        $this->assertDirectoryDoesNotExist($this->checkout.'/.claude/skills/boschifai-gen-storybook');
        $this->assertDirectoryDoesNotExist($this->checkout.'/.claude/commands');
        $this->assertDirectoryDoesNotExist($this->checkout.'/.github/prompts');
        $this->assertFileExists($this->checkout.'/app/Keep.php');
    }

    public function test_a_failed_init_is_reported_with_its_command(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Unknown profile "pipeline"')]);

        $this->expectExceptionMessage('boschifai init --yes --force --profile pipeline');

        $this->prepare();
    }
}
