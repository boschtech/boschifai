<?php

namespace App\Enums;

/**
 * Where an invocation's AI credentials came from. Google Vertex comes later.
 */
enum ClaudeCredentialSource: string
{
    /** BoschifAI's own key (boschifai.claude.api_key). */
    case Platform = 'platform';

    /** The workspace's own Anthropic API key (Settings → AI provider). */
    case Anthropic = 'anthropic';

    /** The workspace's own Amazon Bedrock, through a role in its AWS account. */
    case Bedrock = 'bedrock';
}
