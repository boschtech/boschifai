<?php

namespace App\Models;

use App\Casts\EncryptedCredentials;
use App\Enums\ClaudeCredentialSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's own AI provider credentials. `secrets` is encrypted at rest with the dedicated
 * credentials key (see CredentialsEncrypter — not APP_KEY) and hidden from serialisation;
 * nothing outside the credential resolver and the validator reads it.
 */
class WorkspaceAiCredential extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    protected $fillable = ['workspace_id', 'provider', 'secrets', 'config', 'secret_hint', 'status', 'validation_error', 'validated_at', 'updated_by_user_id'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'provider' => ClaudeCredentialSource::class,
            'secrets' => EncryptedCredentials::class,
            'config' => 'array',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * The credentials a workspace's runs use instead of the platform's, if any. Deliberately
     * regardless of `status` (the result of the last check): a workspace that chose its own key
     * stays on it until someone removes it, rather than silently moving onto the platform's key
     * and credits — a key that stopped working fails its steps with a message saying so.
     */
    public static function activeFor(?int $workspaceId): ?self
    {
        return $workspaceId === null ? null : static::where('workspace_id', $workspaceId)->first();
    }

    public static function hintFor(string $secret): string
    {
        return '…'.substr($secret, -4);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
