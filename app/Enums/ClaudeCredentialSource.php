<?php

namespace App\Enums;

/**
 * Where an invocation's AI credentials came from. Phase 3 adds the workspace's own Anthropic
 * key, AWS Bedrock and Google Vertex.
 */
enum ClaudeCredentialSource: string
{
    /** BoschifAI's own key (boschifai.claude.api_key). */
    case Platform = 'platform';
}
