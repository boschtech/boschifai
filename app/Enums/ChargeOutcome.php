<?php

namespace App\Enums;

/** How a finished Claude step is settled against its hold (see App\Services\Billing\SettlementPolicy). */
enum ChargeOutcome: string
{
    /** Finished (or failed on its own work): charged at cost × markup, at least the minimum. */
    case Charged = 'charged';
    /** Stopped by the user: charged what was actually used, at cost, no minimum. */
    case Partial = 'partial';
    /** The platform's fault (API outage, timeout, crash): nothing charged. */
    case Released = 'released';
}
