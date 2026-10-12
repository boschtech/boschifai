<?php

namespace Tests\Concerns;

use App\Enums\WorkspaceRole;
use App\Models\User;

/**
 * Every API route requires a signed-in user who belongs to a workspace. Test classes that call
 * the API use this trait: Laravel runs `setUpSignsInUser()` automatically before each test (any
 * `setUp<TraitName>` method on a used trait is called), so each test starts signed in as
 * `$this->user`, an owner of `$this->workspace()`.
 */
trait SignsInUser
{
    protected User $user;

    protected function setUpSignsInUser(): void
    {
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test-user@example.com',
            'password' => 'correct-horse-battery-9',
            'email_verified_at' => now(),
        ]);
        $this->workspace()->addMember($this->user, WorkspaceRole::Owner);
        $this->user->switchWorkspace($this->workspace());
        $this->actingAs($this->user);
    }
}
