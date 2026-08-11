<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideGapAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected', 'changes_requested'])],
            // Comment required for rejected/changes_requested — an audit-trail requirement,
            // not a UX nicety, in a regulated environment (plan §6).
            'comment' => ['required_unless:decision,approved', 'nullable', 'string', 'max:2000'],
        ];
    }
}
