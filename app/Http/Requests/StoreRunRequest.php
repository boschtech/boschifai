<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'requirement_text' => ['required', 'string', 'max:20000'],
            // J3 (plan §3): the target file is always human-specified, never inferred — a
            // wrong guess on a bank-integrated, multi-tenant codebase produces confidently
            // wrong tests with no obvious failure signal.
            'target_file_path' => ['required', 'string', 'max:500'],
            // Which RepoConfig this run targets — no longer implicitly hardcoded to "rams"
            // now that repos can be connected via the GitHub App flow.
            'repo_config_id' => ['required', 'integer', 'exists:repo_configs,id'],
            // Set when this run was created via "Edit" on an existing failed/cancelled/rejected
            // run (see plan J5: Runs are immutable — editing never mutates the original row, it
            // creates a new linked one). Optional: omitted for an ordinary fresh submission.
            'previous_run_id' => ['nullable', 'uuid', 'exists:runs,id'],
        ];
    }
}
