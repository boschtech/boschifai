<?php

namespace App\Enums;

/**
 * Where an invocation's AI credentials came from. AWS Bedrock and Google Vertex come later.
 */
enum ClaudeCredentialSource: string
{
    /** BoschifAI's own key (boschifai.claude.api_key). */
    case Platform = 'platform';

    /** The workspace's own Anthropic API key (Settings → AI provider). */
    case Anthropic = 'anthropic';
}
