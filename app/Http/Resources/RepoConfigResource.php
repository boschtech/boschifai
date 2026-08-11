<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepoConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'base_branch' => $this->base_branch,
            'has_docker_image' => filled($this->docker_image),
            'connected_via_github' => $this->github_connection_id !== null,
        ];
    }
}
