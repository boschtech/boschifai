<?php

namespace App\Enums;

/**
 * Where an invocation's AI credentials came from.
 */
enum ClaudeCredentialSource: string
{
    /** BoschifAI's own key (boschifai.claude.api_key). */
    case Platform = 'platform';

    /** The workspace's own Anthropic API key (Settings → AI provider). */
    case Anthropic = 'anthropic';

    /** The workspace's own Amazon Bedrock, through a role in its AWS account. */
    case Bedrock = 'bedrock';

    /** The workspace's own Google Vertex AI, through a service account in its Google Cloud project. */
    case Vertex = 'vertex';
}
