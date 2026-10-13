<?php

namespace App\Console\Commands;

use App\Models\WorkspaceAiCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every stored AI provider credential with the current BOSCHIFAI_CREDENTIALS_KEY.
 * Rotation: set the new key, move the old one to BOSCHIFAI_CREDENTIALS_PREVIOUS_KEYS, restart
 * the app and worker, run this, then remove the old key.
 */
class RotateCredentialsKeyCommand extends Command
{
    protected $signature = 'boschifai:rotate-credentials-key';

    protected $description = 'Re-encrypt stored AI provider credentials with the current key';

    public function handle(): int
    {
        $count = DB::transaction(function () {
            $credentials = WorkspaceAiCredential::lockForUpdate()->get();
            foreach ($credentials as $credential) {
                // Reading decrypts with whichever key (current or previous) it was written with;
                // writing it back encrypts it with the current key.
                $credential->secrets = $credential->secrets;
                $credential->save();
            }

            return $credentials->count();
        });

        $this->info("Re-encrypted {$count} stored credential(s) with the current key. The previous key can now be removed.");

        return self::SUCCESS;
    }
}
