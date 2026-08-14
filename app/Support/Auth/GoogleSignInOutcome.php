<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

/**
 * Who signed in, and whether this product had ever heard of them before (Increment J3c2).
 *
 * ⚠️ `created` EXISTS FOR EXACTLY ONE DECISION AND IT IS NOT COSMETIC: `Verified` is dispatched for a NEW
 * user only. `App\Listeners\Auth\SendWelcomeEmail` listens on that event, and firing it on every sign-in
 * would send a returning member a welcome email each time they used the button.
 *
 * ⚠️ AND THE EVENT IS `Verified`, NEVER `Registered`. The framework registers
 * `SendEmailVerificationNotification` on `Registered` unconditionally, so firing it would email a
 * verification link for an address Google has already proved — and `JoinTenantOnRegistration` would then
 * join the person at `viewer` with no Suspended refusal and no Invited-role arm, i.e. a second and weaker
 * implementation of ADR-0016 §D20 running beside the one this flow deliberately reuses.
 */
final readonly class GoogleSignInOutcome
{
    public function __construct(
        public User $user,
        public bool $created,
    ) {}
}
