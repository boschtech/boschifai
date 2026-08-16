<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectGithubRepositoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'repositories' => ['required', 'array', 'min:1'],
            'repositories.*.full_name' => ['required', 'string', 'regex:/^[^\/\s]+\/[^\/\s]+$/'],
            'repositories.*.default_branch' => ['required', 'string'],
        ];
    }
}
