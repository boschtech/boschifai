<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RunListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement_summary' => Str::limit(trim(explode("\n", trim($this->requirement_text))[0] ?? ''), 80),
            'repo_name' => $this->repoConfig->display_name,
            'state' => $this->state->value,
            'confidence_score' => $this->confidence_score,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
