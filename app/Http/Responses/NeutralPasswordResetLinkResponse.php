<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * The same reply to every "forgot password" request — whether the address has an account, has
 * none, or was rate-limited. Fortify's defaults answer "We can't find a user with that email
 * address" for unknown addresses, which lets anyone check who has an account (a POPIA concern).
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = "If an account exists for that email address, we've sent a link to reset its password.";

    /** Fortify passes the password broker's status; deliberately not used. */
    public function __construct(string $status = '') {}

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? response()->json(['message' => self::MESSAGE])
            : back()->with('status', self::MESSAGE);
    }
}
