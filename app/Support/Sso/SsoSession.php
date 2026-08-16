<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Tenant\Sso\SsoAcsController;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\SsoAuthRequest;
use App\Services\Sso\SsoAuthRequestService;
use Illuminate\Contracts\Session\Session;

/**
 * What this browser knows about its own SSO flows (Phase 4, P1c and P1e — ADR-0016 §D23, §D27).
 *
 * Two keys, written at two different moments by two different requests, and read by two more. It exists as a
 * class rather than string literals scattered across five files because those files must agree, and because
 * the reasoning below has to live somewhere.
 *
 *   · {@see AUTHENTICATED_AT} — how this session was established. Written once a sign-in completes.
 *   · {@see PENDING_LOGIN_IDS} — which sign-ins this browser has STARTED but not finished (P1e).
 *
 * ── ⚠️ WHY THE SESSION, AND NOT A COLUMN ON `users` ─────────────────────────────────────────────────
 * The obvious alternative is to mark the ACCOUNT as SSO-backed — a `sso_provisioned_at`, or an inference
 * from the unusable random password. Both answer the wrong question. The right question is not "can this
 * person use SSO" but "how did the session in front of me prove who it is", and those genuinely differ:
 *
 *   · A member who registered with a password, in a workspace that later enabled SSO, still holds a
 *     password and should keep the password prompt. An account-level flag would take it away.
 *   · The same person signing in through the IdP on Monday and with their password on Tuesday is one
 *     account and two sessions, and only one of those two sessions can be re-proved at the IdP.
 *
 * And the unusable-password inference is not merely inelegant, it is unavailable: a hash of 64 random bytes
 * is indistinguishable from a hash of a real password. There is nothing to read.
 *
 * ── WHAT IT COSTS, STATED RATHER THAN DISCOVERED ────────────────────────────────────────────────────
 * A session established before P1c deployed carries no marker and therefore gets the password prompt. For
 * an SSO member that is the pre-P1c behaviour — a dead end — and it heals on their next sign-in. Nothing
 * backfills it, because nothing can: the fact was never recorded.
 */
final class SsoSession
{
    /**
     * Namespaced under `sso.` rather than `auth.` on purpose: `auth.password_confirmed_at` belongs to
     * Laravel and `Session::forget('auth.*')` is not a thing anyone does, but a future framework upgrade
     * owning the `auth.` prefix is a collision this key does not need to be exposed to.
     */
    public const AUTHENTICATED_AT = 'sso.authenticated_at';

    /**
     * Record that an identity provider established this session.
     *
     * ⚠️ CALL THIS AFTER `Auth::login()`, NEVER BEFORE. `Auth::login()` calls `Session::migrate(true)`, which
     * regenerates the session id; Laravel carries the data across today, so writing first APPEARS to work and
     * would break silently the day that stops being true. The ACS's own docblock makes the same point about
     * ordering, for the same reason.
     */
    public static function markAuthenticated(Session $session): void
    {
        $session->put(self::AUTHENTICATED_AT, time());
    }

    /** Whether an identity provider is what put a user in this session. */
    public static function isSsoSession(Session $session): bool
    {
        return $session->has(self::AUTHENTICATED_AT);
    }

    /**
     * The `request_id`s of the sign-ins THIS browser has started (P1e).
     *
     * ── ⚠️ WHY THE MINT WRITES ANYTHING AT ALL, WHEN IT USED TO WRITE NOTHING ───────────────────────
     * `request_id` and `InResponseTo` prove that THIS SP MINTED THIS FLOW. They prove nothing about who is
     * HOLDING it. So an attacker with an account at the tenant's own directory could start a flow, obtain a
     * real signed assertion for it, withhold the identity provider's auto-POST form, and induce a victim's
     * browser to submit it — and the victim would be signed in as the attacker. Reading provenance as origin
     * is the same error this repository already records against ADR-0009 §D3.
     *
     * The ACS cannot close it: it is a cross-site POST, `SameSite=Lax` sends it no cookie, and since P1e it
     * has no session at all. So the comparison happens one hop later, on the same-site GET the ACS hands
     * back — and this is the value it compares against.
     *
     * ── ⚠️ THE FLOW'S OWN ID, NEVER MERELY "SOME VALUE" ─────────────────────────────────────────────
     * {@see hasPendingLogin()} asks whether THIS request id is one this browser minted, not whether the
     * browser has minted anything. The weaker check is defeated by luring the victim through
     * `/sso/saml/login` first to populate their session, which is the refinement
     * {@see GoogleRedirectController::FLOW_SID} was written to make on the sibling flow.
     *
     * ── A LIST, WHICH DIVERGES FROM `google.flow_sid`'s SINGLE VALUE ON PURPOSE ─────────────────────
     * The step-up arm is concurrent for free — its binding is `sso_auth_requests.user_id`, so any number of
     * step-ups can be in flight at once. A single-valued key here would make the LOGIN arm strictly less
     * concurrent than the step-up arm: a second tab would silently kill the first, and the member would meet
     * a bare 404 AFTER their identity provider had already said yes — the one failure §D19 guarantees is
     * never explained to them.
     *
     * ⚠️ CAPPED, BECAUSE THE MINT IS UNAUTHENTICATED. `GET /sso/saml/login` writes one entry per hit and
     * anyone holding the hostname can hit it, so an unbounded list is the same unbounded-growth-on-an-
     * anonymous-write-path problem {@see SsoAuthRequestService::trim()} exists for, one layer up. **Stated
     * cost:** start six sign-ins in one browser without finishing any and the first stops being completable.
     */
    public const PENDING_LOGIN_IDS = 'sso.pending_login_ids';

    /**
     * Five is far above what anyone opens by accident and far below anything that matters: each entry is the
     * 33 characters {@see SsoAuthRequest::mintRequestId()} produces.
     */
    private const PENDING_LIMIT = 5;

    /** Record that this browser started the flow named by `$requestId`. */
    public static function rememberPendingLogin(Session $session, string $requestId): void
    {
        // Filtered before appending so a repeated id moves to the newest position rather than occupying two
        // slots — a browser that retries the same flow must not evict four of its own.
        $pending = array_values(array_filter(
            self::pendingLogins($session),
            static fn (string $pending): bool => $pending !== $requestId,
        ));

        $pending[] = $requestId;

        $session->put(self::PENDING_LOGIN_IDS, array_slice($pending, -self::PENDING_LIMIT));
    }

    /**
     * Whether this browser started the flow named by `$requestId`.
     *
     * A plain `in_array` with strict comparison: reaching this comparison at all requires already holding the
     * session, so there is no oracle here to grind against.
     */
    public static function hasPendingLogin(Session $session, string $requestId): bool
    {
        return in_array($requestId, self::pendingLogins($session), true);
    }

    /**
     * Drop a flow once it has been spent, so a session cannot carry a binding it has already used.
     *
     * ⚠️ Call it AFTER `Auth::login()`, for {@see markAuthenticated()}'s reason: the login migrates the
     * session id, and Laravel carrying the data across is a convenience rather than a contract.
     */
    public static function forgetPendingLogin(Session $session, string $requestId): void
    {
        $remaining = array_values(array_filter(
            self::pendingLogins($session),
            static fn (string $pending): bool => $pending !== $requestId,
        ));

        if ($remaining === []) {
            $session->forget(self::PENDING_LOGIN_IDS);

            return;
        }

        $session->put(self::PENDING_LOGIN_IDS, $remaining);
    }

    /**
     * ⚠️ Everything that is not a string is discarded rather than trusted. The session is `json`-serialised
     * (`config/session.php`) and survives deploys, so a value written by an older shape of this class — or by
     * anything else that ever claims this key — must not reach a comparison as an array or a null.
     *
     * @return list<string>
     */
    private static function pendingLogins(Session $session): array
    {
        $pending = $session->get(self::PENDING_LOGIN_IDS, []);

        if (! is_array($pending)) {
            return [];
        }

        return array_values(array_filter($pending, 'is_string'));
    }
}
