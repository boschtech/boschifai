<?php

namespace App\Console\Commands;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a user who can sign in. Self-registration is off until workspaces exist (see
 * config/fortify.php), so this is how users are added — including the very first one after
 * deploying sign-in, without whom nobody could reach the app.
 *
 * Every user belongs to a workspace: --workspace picks it by name (created if missing, owned by
 * this user unless --role says otherwise).
 *
 * The password is always typed at a hidden prompt: never accepted as an argument (it would land
 * in shell history and process lists) and never generated and printed.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'boschifai:create-user {email}
        {--name= : Display name (defaults to the part of the email before @)}
        {--workspace=Default : Workspace to add them to (created if it doesn\'t exist)}
        {--role= : owner, admin or member (default: owner of a new workspace, member of an existing one)}';

    protected $description = 'Create a user who can sign in to BoschifAI';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command asks for the password at a hidden prompt, so it must be run interactively.');

            return self::FAILURE;
        }

        $workspaceName = trim((string) $this->option('workspace')) ?: 'Default';
        $workspace = Workspace::where('name', $workspaceName)->first();
        $role = $this->option('role')
            ? WorkspaceRole::tryFrom(strtolower($this->option('role')))
            : ($workspace ? WorkspaceRole::Member : WorkspaceRole::Owner);

        if (! $role) {
            $this->error('The role must be one of: owner, admin, member.');

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

        $user = DB::transaction(function () use ($name, $email, $password, &$workspace, $workspaceName, $role) {
            $workspace ??= Workspace::create(['name' => $workspaceName]);
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            // Created by an administrator, so the address doesn't need a separate verification step.
            $user->forceFill(['email_verified_at' => now(), 'current_workspace_id' => $workspace->id])->save();
            $workspace->addMember($user, $role);

            return $user;
        });

        $this->info("Created {$user->email} as {$role->value} of the \"{$workspace->name}\" workspace. They can now sign in.");

        return self::SUCCESS;
    }
}
