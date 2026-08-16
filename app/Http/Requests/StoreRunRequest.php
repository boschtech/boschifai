<?php

namespace App\Http\Requests;

use App\Enums\RunType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'run_type' => ['sometimes', 'string', Rule::in(array_column(RunType::cases(), 'value'))],
            // Holds a written requirement (`requirement` mode) or a plain coverage instruction
            // (`coverage` mode, e.g. "increase coverage for the billing module") — both are free
            // text end-to-end (see RunGapAnalysisJob), so one column/rule serves both.
            'requirement_text' => ['required', 'string', 'max:20000'],
            // J3 (plan §3): for `requirement` mode the target file is always human-specified,
            // never inferred — a wrong guess on a bank-integrated, multi-tenant codebase produces
            // confidently wrong tests with no obvious failure signal. `coverage` mode has no
            // target file yet at submission time — the pipeline works it out itself (see
            // RunTestPlanJob's RECOMMENDED_TARGET_FILE extraction) — so it's optional here;
            // prepareForValidation() below fills in '' so the NOT NULL column is still satisfied.
            'target_file_path' => [
                Rule::requiredIf(fn () => $this->runTypeInput() === RunType::Requirement->value),
                'nullable', 'string', 'max:500',
            ],
            // Which RepoConfig this run targets — no longer implicitly hardcoded to "rams"
            // now that repos can be connected via the GitHub App flow.
            'repo_config_id' => ['required', 'integer', 'exists:repo_configs,id'],
            // Optional supporting files uploaded straight from the user's own machine (a spec
            // doc, a screenshot, a sample payload) — copied into the checkout as extra context
            // for the AI (see RunGapAnalysisJob), not run through validation for content type,
            // since Claude reads whatever text/image content is there rather than this app
            // needing to understand the file itself. 10MB/file matches the raised
            // upload_max_filesize in docker/app/Dockerfile — PHP itself rejects anything larger
            // before this rule would ever see it. max:5 matches php.ini's own max_file_uploads.
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
            // Set when this run was created via "Edit" on an existing failed/cancelled/rejected
            // run (see plan J5: Runs are immutable — editing never mutates the original row, it
            // creates a new linked one). Optional: omitted for an ordinary fresh submission.
            'previous_run_id' => ['nullable', 'uuid', 'exists:runs,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $standaloneTypes = [RunType::BuildSkills->value, RunType::BuildKnowledgeBase->value];

        if (($this->runTypeInput() === RunType::Coverage->value || in_array($this->runTypeInput(), $standaloneTypes, true))
            && ! $this->filled('target_file_path')) {
            $this->merge(['target_file_path' => '']);
        }

        // The two standalone actions have no user-typed instruction at all — just "pick a repo
        // and go" (see StandaloneActionCreatePage.vue) — so this fills in a fixed, readable
        // description server-side rather than asking the frontend to fabricate one, the same way
        // target_file_path's '' placeholder above is filled in here rather than by the form.
        if (in_array($this->runTypeInput(), $standaloneTypes, true) && ! $this->filled('requirement_text')) {
            $this->merge(['requirement_text' => $this->runTypeInput() === RunType::BuildSkills->value
                ? 'Build a project skill for this repository.'
                : 'Build a knowledge base for this repository.',
            ]);
        }
    }

    private function runTypeInput(): string
    {
        return $this->input('run_type', RunType::Requirement->value);
    }
}
