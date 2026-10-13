<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** Checkout started; waiting for the provider. */
    case Pending = 'pending';
    /** Confirmed with the provider and the credits granted. */
    case Succeeded = 'succeeded';
    /** The provider reported it failed or was abandoned. */
    case Failed = 'failed';
    /** The provider says it was paid but the amount or currency doesn't match the quote — no
     *  credits granted; someone has to look at it. */
    case NeedsReview = 'needs_review';
}
