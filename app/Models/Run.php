<?php

namespace App\Models;

use App\Enums\RunState;
use App\Enums\RunType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Run extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'repo_config_id',
        'run_type',
        'requirement_text',
        'target_file_path',
        'attachment_filenames',
        'execution_recipe',
        'state',
        'branch_name',
        'pr_number',
        'pr_url',
        'head_sha',
        'confidence_score',
        'confidence_breakdown',
        'testability_score',
        'test_case_verdict',
        'generated_file_path',
        'previous_run_id',
        'blocked_reason',
        'failed_step',
        'error_message',
        'rejection_comment',
        'created_by',
        'created_by_user_id',
        'cancel_requested_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => RunState::class,
            'run_type' => RunType::class,
            'attachment_filenames' => 'array',
            'execution_recipe' => 'array',
            'confidence_breakdown' => 'array',
            'confidence_score' => 'integer',
            'pr_number' => 'integer',
            'cancel_requested_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** A run always belongs to its repository's workspace — set here, never by callers. */
    protected static function booted(): void
    {
        static::creating(function (Run $run) {
            $run->workspace_id = RepoConfig::whereKey($run->repo_config_id)->value('workspace_id');
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function repoConfig(): BelongsTo
    {
        return $this->belongsTo(RepoConfig::class);
    }

    public function previousRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_run_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(RunArtifact::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(HumanApproval::class);
    }

    public function executionResult(): HasOne
    {
        return $this->hasOne(TestExecutionResult::class);
    }

    public function ciCheckResult(): HasOne
    {
        return $this->hasOne(CiCheckResult::class);
    }

    public function stepByKey(string $key): ?RunStep
    {
        return $this->steps->firstWhere('key', $key);
    }

    public function artifactOfKind(string $kind): ?RunArtifact
    {
        return $this->artifacts->firstWhere('kind', $kind);
    }
}
