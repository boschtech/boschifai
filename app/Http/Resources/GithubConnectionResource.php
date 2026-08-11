<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GithubConnectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'github_login' => $this->github_login,
            'connected_repo_count' => $this->when(
                isset($this->repo_configs_count),
                fn () => $this->repo_configs_count
            ),
        ];
    }
}
