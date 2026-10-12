<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a user who can sign in. Self-registration is off until workspaces exist (see
 * config/fortify.php), so this is how users are added — including the very first one after
 * deploying sign-in, without whom nobody could reach the app.
 *
 * The password is always typed at a hidden prompt: never accepted as an argument (it would land
 * in shell history and process lists) and never generated and printed.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'boschifai:create-user {email} {--name= : Display name (defaults to the part of the email before @)}';

    protected $description = 'Create a user who can sign in to BoschifAI';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command asks for the password at a hidden prompt, so it must be run interactively.');

            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?: strstr($email, '@', true);

        $password = (string) $this->secret('Password (at least 12 characters, letters and numbers)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        // Created by an administrator, so the address doesn't need a separate verification step.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Created {$user->email}. They can now sign in.");

        return self::SUCCESS;
    }
}
