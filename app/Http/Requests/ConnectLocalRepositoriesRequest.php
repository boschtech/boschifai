<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectLocalRepositoriesRequest extends FormRequest
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
            'repositories.*.path' => ['required', 'string'],
        ];
    }
}
