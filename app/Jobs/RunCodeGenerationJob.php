<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(StepExecutionService $steps, PromptBuilder $prompts, CancellationChecker $cancellation): void
    {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::CodeGeneration->value, 'Cancelled by user before this step started.');

            return;
        }

        $testCasesArtifact = $run->artifactOfKind(ArtifactKind::TestCasesMarkdown->value);

        $outcome = $steps->run(
            $run,
            RunStepKey::CodeGeneration->value,
            $prompts->codeGeneration($run->target_file_path, $testCasesArtifact?->relative_path ?? ''),
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

        $generatedFile = $outcome->artifactOfKind(ArtifactKind::GeneratedTestPhp->value);

        if ($generatedFile === null) {
            // No new test file was detected under tests/Feature|Unit — most likely the §3
            // verification spike's open question about --file semantics needs revisiting for
            // this specific target, not something to silently paper over.
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::CodeGeneration->value,
                'error_message' => 'No generated test file was detected via git-status diff after /boschifai-gen-component.',
            ]);

            return;
        }

        $run->update([
            'generated_file_path' => $generatedFile->relative_path,
            'state' => RunState::LocalExecutionRunning,
        ]);

        RunLocalTestExecutionJob::dispatch($run->id);
    }
}
