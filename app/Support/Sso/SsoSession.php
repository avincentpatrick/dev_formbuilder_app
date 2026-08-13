<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Http\Controllers\Tenant\Sso\SsoAcsController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Contracts\Session\Session;

/**
 * How THIS session was established (Phase 4, P1c — ADR-0016 §D23).
 *
 * One key, written by {@see SsoAcsController}'s login arm and read by {@see RequireRecentPassword} to decide
 * which step-up to offer. It exists as a class rather than a string literal in two files because the two
 * files must agree, and because the reasoning below has to live somewhere.
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
}
