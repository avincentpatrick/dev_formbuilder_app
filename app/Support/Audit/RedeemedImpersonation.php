<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use App\Services\Admin\ImpersonationService;

/**
 * What a successful token redemption yields — Increment I11b.
 *
 * A pair rather than a bare {@see User}, because the caller needs BOTH halves and only one of them is
 * derivable from the request. {@see ImpersonationService::redeem()} is the last place the operator's
 * identity is knowable: it comes off the token row, which that method consumes and which nothing else
 * reads. Returning only the user would leave the controller reaching back into the table it just spent —
 * or, worse, trusting a session key that has not been written yet.
 *
 * Deliberately NOT a model or an array: the two ids mean different things ({@see $user} is whose authority
 * the session will run under, {@see $operatorId} is who is really at the keyboard), and that distinction is
 * the entire subject of I11a. An `array{user: …, operator_id: …}` would let a caller destructure them in
 * the wrong order without a type error.
 */
final readonly class RedeemedImpersonation
{
    public function __construct(
        /** The member to log in as — the EFFECTIVE actor, `audits.user_id`. */
        public User $user,
        /** The platform operator driving — `audits.acting_as_user_id`, and the session marker. */
        public string $operatorId,
    ) {}
}
