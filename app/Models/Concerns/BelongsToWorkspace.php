<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models owned by a workspace. Scoping is explicit rather than a global scope: background
 * jobs have no signed-in user, so they load records by id and get the workspace from the record
 * itself; request code filters with forWorkspace(), and route model binding (resolveRouteBinding)
 * only ever finds records in the current user's workspace — another workspace's id is a 404.
 */
trait BelongsToWorkspace
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where($this->qualifyColumn('workspace_id'), $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->forWorkspace(app(CurrentWorkspace::class)->id())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
