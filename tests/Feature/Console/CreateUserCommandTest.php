<?php

namespace Tests\Feature\Console;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT = 'Password (at least 12 characters, letters and numbers)';

    public function test_creates_a_verified_user_from_a_hidden_password_prompt(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'Ops@Example.com', '--name' => 'Ops Admin'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-9')
            ->expectsOutput('Created ops@example.com as owner of the "Default" workspace. They can now sign in.')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('ops@example.com', $user->email);
        $this->assertSame('Ops Admin', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery-9', $user->password));
    }

    public function test_the_name_defaults_to_the_start_of_the_email(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'jane.doe@example.com'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-9')
            ->assertSuccessful();

        $this->assertSame('jane.doe', User::sole()->name);
    }

    public function test_rejects_a_weak_or_mismatched_password(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion(self::PROMPT, 'short1')
            ->expectsQuestion('Confirm password', 'short1')
            ->expectsOutputToContain('at least 12 characters')
            ->assertFailed();

        $this->artisan('boschifai:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'different-horse-battery-9')
            ->expectsOutputToContain('confirmation does not match')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_an_email_that_already_has_an_account(): void
    {
        User::create(['name' => 'x', 'email' => 'ops@example.com', 'password' => 'correct-horse-battery-9']);

        $this->artisan('boschifai:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-9')
            ->expectsOutputToContain('already been taken')
            ->assertFailed();
    }

    public function test_refuses_to_run_without_an_interactive_prompt(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'ops@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('must be run interactively')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_adds_a_user_to_an_existing_workspace_as_a_member_by_default(): void
    {
        $agency = Workspace::create(['name' => 'Agency']);

        $this->artisan('boschifai:create-user', ['email' => 'dev@example.com', '--workspace' => 'Agency'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-9')
            ->expectsOutput('Created dev@example.com as member of the "Agency" workspace. They can now sign in.')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(WorkspaceRole::Member, $user->roleIn($agency));
        $this->assertSame($agency->id, $user->current_workspace_id);
        $this->assertSame(1, Workspace::count());
    }

    public function test_the_role_can_be_chosen_and_must_be_valid(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'lead@example.com', '--workspace' => 'Agency', '--role' => 'Admin'])
            ->expectsQuestion(self::PROMPT, 'correct-horse-battery-9')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-9')
            ->assertSuccessful();
        $this->assertSame(WorkspaceRole::Admin, User::sole()->roleIn(Workspace::sole()));

        $this->artisan('boschifai:create-user', ['email' => 'x@example.com', '--role' => 'superuser'])
            ->expectsOutput('The role must be one of: owner, admin, member.')
            ->assertFailed();
    }

    public function test_a_failed_validation_creates_no_workspace(): void
    {
        $this->artisan('boschifai:create-user', ['email' => 'ops@example.com', '--workspace' => 'Brand New'])
            ->expectsQuestion(self::PROMPT, 'short1')
            ->expectsQuestion('Confirm password', 'short1')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
    }
}
