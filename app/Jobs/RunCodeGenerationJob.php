<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunType;
use App\Models\Run;
use App\Services\ArtifactCollection\GitStatusDiffCollector;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Pipeline\StepOutcome;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

/** Plan §4 step 5 — the final generation step, producing the actual PHPUnit Feature test file. */
class RunCodeGenerationJob implements ShouldQueue
{
    use Queueable;

    // See RunTestCaseGenerationJob's $timeout comment for why this override is not optional.
    // Must stay above boschifai.claude.timeouts.code_generation (1800s) — otherwise Laravel's
    // own queue worker kills the job before HeadlessClaudeInvoker's internal timeout ever gets
    // a chance to fire gracefully and record a proper `timed_out` result.
    public int $timeout = 1900;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        StepExecutionService $steps,
        PromptBuilder $prompts,
        CancellationChecker $cancellation,
        RunWorkspaceManager $workspace,
        ClaudeTranscriptParser $transcriptParser,
        GitStatusDiffCollector $diffCollector,
    ): void {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::CodeGeneration->value, 'Cancelled by user before this step started.');

            return;
        }

        $testCasesArtifact = $run->artifactOfKind(ArtifactKind::TestCasesMarkdown->value);

        $prompt = $run->run_type === RunType::Coverage
            ? $prompts->coverageCodeGeneration($run->target_file_path, $testCasesArtifact?->relative_path ?? '')
            : $prompts->codeGeneration($run->target_file_path, $testCasesArtifact?->relative_path ?? '');

        $outcome = $steps->run(
            $run,
            RunStepKey::CodeGeneration->value,
            $prompt,
            config('boschifai.claude.timeouts.code_generation'),
        );

        if ($outcome->claudeResult->cancelled) {
            return;
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::CodeGeneration->value,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        $generatedFile = $outcome->artifactOfKind(ArtifactKind::GeneratedTest->value);
        $generatedFilePath = $generatedFile?->relative_path
            ?? $this->resolveEditedTestFile($run, $outcome, $workspace, $transcriptParser, $diffCollector);

        if ($generatedFilePath === null) {
            // No new test file was detected under tests/Feature|Unit, and the transcript's own
            // GENERATED_TEST_FILE: sentinel (see PromptBuilder::codeGeneration()'s docblock —
            // the fallback for an edit to an already-existing test file) either wasn't present
            // or didn't point at a real file. Most likely the §3 verification spike's open
            // question about --file semantics needs revisiting for this specific target, not
            // something to silently paper over.
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::CodeGeneration->value,
                'error_message' => 'No generated test file was detected via git-status diff after /boschifai-gen-component.',
            ]);

            return;
        }

        $run->update([
            'generated_file_path' => $generatedFilePath,
            'state' => RunState::LocalExecutionRunning,
        ]);

        RunLocalTestExecutionJob::dispatch($run->id);
    }

    /**
     * Fallback for when the git-status diff finds nothing new — confirmed as a real, recurring
     * failure mode live: a repo's shared, persistent checkout can carry a test file left over
     * from an earlier run against the same target, already untracked before this step even
     * starts. Editing that file's content doesn't change its `git status` line at all, so it
     * never looks "new" to GitStatusDiffCollector, even though Claude did exactly the right
     * thing. Validated two ways before being trusted: it must look like a real generated-test
     * filename for its language (the same convention classify() itself uses — guards against
     * Claude reporting an unrelated or fabricated path), and the file must actually exist on disk.
     */
    private function resolveEditedTestFile(
        Run $run,
        StepOutcome $outcome,
        RunWorkspaceManager $workspace,
        ClaudeTranscriptParser $transcriptParser,
        GitStatusDiffCollector $diffCollector,
    ): ?string {
        $reportedPath = $transcriptParser->extractGeneratedTestFile($outcome->claudeResult->rawTranscriptPath);

        if ($reportedPath === null || ! $diffCollector->looksLikeGeneratedTest(basename($reportedPath), $reportedPath)) {
            return null;
        }

        return File::exists($workspace->path($run).'/'.$reportedPath) ? $reportedPath : null;
    }
}
