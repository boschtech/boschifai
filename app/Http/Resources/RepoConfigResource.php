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
            'connected_via_github' => $this->github_connection_id !== null,
            // Populated for both GitHub-connected repos (the real GitHub org/user login) and
            // local ones (see LocalRepoController — currently always null there, since a local
            // checkout has no "organisation" concept) — used to group the "Connected
            // organisations" summary on the Connect Repo page.
            'github_owner' => $this->github_owner,
        ];
    }
}
