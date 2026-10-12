<?php

namespace App\Enums;

/**
 * Who pays the AI provider for an invocation. Recorded on every claude_invocations row so usage
 * can later be charged (Phase 2 prepaid credits) or left to the customer's own provider account
 * (Phase 3, which adds that case).
 */
enum ClaudeBillingMode: string
{
    /** The platform's own Anthropic account pays. */
    case Platform = 'platform';
}
