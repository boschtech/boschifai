<?php

namespace App\Services\Billing;

use App\Support\Billing\MicroUsd;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $availableMicro, public readonly int $requiredMicro)
    {
        parent::__construct(sprintf(
            'Not enough credits: US$%s needed, US$%s available.',
            MicroUsd::format($requiredMicro, 2),
            MicroUsd::format($availableMicro, 2),
        ));
    }
}
