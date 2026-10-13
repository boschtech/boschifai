<?php

namespace App\Services\ClaudeRunner\Credentials;

use RuntimeException;

/**
 * A step's credentials couldn't be obtained (e.g. AWS refused the workspace's Bedrock role).
 * The message is for people: StepExecutionService fails the step with it.
 */
class ClaudeCredentialsUnavailable extends RuntimeException {}
