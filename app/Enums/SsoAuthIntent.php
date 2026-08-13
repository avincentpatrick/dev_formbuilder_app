<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\RequireRecentPassword;

/**
 * Why an AuthnRequest was minted (Phase 4, P1a). Persisted on `sso_auth_requests.intent` and pinned by the
 * `sso_auth_requests_intent_check` CHECK generated from {@see values()}.
 *
 *   - {@see Login}  — an unauthenticated visitor is establishing a session.
 *   - {@see StepUp} — an ALREADY-authenticated user is re-proving identity for a high-blast-radius mutation,
 *     the SSO equivalent of {@see RequireRecentPassword}'s password confirmation (user decision 2026-08-13).
 *
 * ── THIS ENUM IS LOAD-BEARING, NOT DECORATIVE ─────────────────────────────────────────────────────────
 * It is what authorises the ACS to stamp `auth.password_confirmed_at`. Without it, ANY successful assertion
 * would refresh the step-up clock — so an attacker who could induce a plain login round trip (which is
 * unauthenticated by definition, and which the IdP may satisfy from an existing IdP session without
 * re-prompting) would silently clear the 15-minute gate guarding role assignment, member removal and
 * ownership transfer. The stamp therefore requires BOTH `intent === StepUp` AND a matching `user_id` on the
 * consumed request row AND `ForceAuthn="true"` on the request that produced the assertion.
 *
 * Read the three together: the intent says what we asked for, `user_id` says who we asked about, and
 * `force_authn` says the IdP was told not to answer from cache. Any one alone is forgeable or stale.
 */
enum SsoAuthIntent: string
{
    case Login = 'login';
    case StepUp = 'step_up';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether an assertion consumed for this intent may refresh the step-up clock.
     *
     * Necessary but NOT sufficient — the ACS additionally requires the request row to name the currently
     * authenticated user and to have carried `ForceAuthn`. See the class docblock for why all three.
     */
    public function mayStampPasswordConfirmation(): bool
    {
        return $this === self::StepUp;
    }
}
