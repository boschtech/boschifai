<?php

namespace App\Models;

use App\Enums\ArtifactKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunArtifact extends Model
{
    protected $fillable = [
        'run_id',
        'kind',
        'relative_path',
        'content',
        'sha256',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public static function makeFromContent(string $runId, ArtifactKind $kind, string $relativePath, string $content): self
    {
        return new self([
            'run_id' => $runId,
            'kind' => $kind,
            'relative_path' => $relativePath,
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ]);
    }
}
