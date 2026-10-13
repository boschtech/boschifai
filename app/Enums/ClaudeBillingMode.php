<?php

namespace App\Enums;

/**
 * Who pays the AI provider for an invocation. Recorded on every claude_invocations row so usage
 * be charged in prepaid credits, or left to the customer's own provider account.
 */
enum ClaudeBillingMode: string
{
    /** The platform's own Anthropic account pays. */
    case Platform = 'platform';

    /** The workspace's own provider account pays (their Anthropic key); no credits are used. */
    case CustomerAccount = 'customer_account';
}
