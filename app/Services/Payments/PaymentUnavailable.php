<?php

namespace App\Services\Payments;

use RuntimeException;

/** A checkout that can't be offered (unknown pack, provider not enabled, no exchange rate). */
class PaymentUnavailable extends RuntimeException {}
