<?php

namespace App\Services\ClaudeRunner\Credentials;

/** AWS refused to give BoschifAI credentials for a workspace's Bedrock role. */
class BedrockAccessDenied extends ClaudeCredentialsUnavailable {}
