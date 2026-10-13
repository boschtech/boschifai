<?php

namespace App\Console\Commands;

use App\Support\Security\CredentialsEncrypter;
use Illuminate\Console\Command;

/**
 * Prints a new key for BOSCHIFAI_CREDENTIALS_KEY. Prints only — it never writes .env, so the key
 * goes wherever this environment keeps secrets (AWS SSM in production).
 */
class GenerateCredentialsKeyCommand extends Command
{
    protected $signature = 'boschifai:generate-credentials-key';

    protected $description = 'Print a new encryption key for stored AI provider credentials';

    public function handle(): int
    {
        $this->line(CredentialsEncrypter::generateKey());
        $this->info('Set it as BOSCHIFAI_CREDENTIALS_KEY (in production: AWS SSM). Keep it secret, and back it up — stored keys are unreadable without it.');

        return self::SUCCESS;
    }
}
