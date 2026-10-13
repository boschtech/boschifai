<?php

namespace App\Enums;

/** Kinds of movement in the credit ledger (see App\Services\Billing\CreditLedger). */
enum CreditEntryType: string
{
    /** Credits bought (Phase 2 payments). */
    case Purchase = 'purchase';
    /** Credits reserved for a step about to run: available → held. */
    case Hold = 'hold';
    /** What a finished step actually cost, taken from its hold. */
    case Charge = 'charge';
    /** The unused part of a hold, returned: held → available. */
    case Release = 'release';
    /** Manual credit or debit by a platform admin, with a reason. */
    case Adjustment = 'adjustment';
    /** Unused credits of a lot past its expiry date (CreditLedger::expire). */
    case Expiry = 'expiry';
}
