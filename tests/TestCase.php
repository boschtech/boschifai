<?php

namespace Tests;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?Workspace $testWorkspace = null;

    /**
     * The workspace a test's data belongs to (created on first use). Repositories and GitHub
     * connections must have one; runs and Claude invocations take theirs from the repository.
     * SignsInUser makes the signed-in user an owner of this same workspace.
     */
    protected function workspace(): Workspace
    {
        return $this->testWorkspace ??= Workspace::create(['name' => 'Test Workspace']);
    }
}
