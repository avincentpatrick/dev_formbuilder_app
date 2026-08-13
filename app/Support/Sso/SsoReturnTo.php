<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\SsoAuthRequest;

/**
 * Reduces a destination to something that cannot be an open redirect (Phase 4, P1c — ADR-0016 §D25).
 *
 * `sso_auth_requests.return_to` is written by the SP when it mints a step-up request and read again once the
 * assertion has come back. Its migration already forbids the obvious mistake — populating it from SAML
 * `RelayState`, which is attacker-controllable round-trip data — but the value this class receives comes from
 * `session('url.intended')`, and "the framework wrote it" is not the same as "it is safe to redirect to".
 *
 * ── THE RULE IS A SHAPE, NOT A COMPARISON ───────────────────────────────────────────────────────────
 * Nothing here compares hosts, because a host comparison is a check that can be got wrong and that keeps
 * being got wrong: `https://evil.test\@ours.test`, a case difference, a trailing dot, a port, an IDN
 * homograph. What is stored instead is a PATH — a string beginning with exactly one `/` and no scheme, no
 * authority and no second leading slash. A browser resolves that against the origin it is already on, so the
 * destination is the current tenant's host by construction and there is no comparison left to get wrong.
 *
 * ⚠️ THE SECOND SLASH IS THE WHOLE ATTACK. `//evil.test/x` is a protocol-relative URL: `Location: //evil.test/x`
 * sends the browser to another origin while looking, in a log and in a code review, exactly like a path.
 * `\\evil.test` is the same thing after a browser normalises backslashes, which is why both characters are
 * rejected in the second position rather than only `/`.
 *
 * Applied on the way IN (so the column can only ever hold a path) and again on the way OUT (so a row written
 * by some future caller, or edited in the database, still cannot redirect a member off-site). Validating
 * once would be enough today and is exactly the kind of "enough today" that ADR-0016 keeps paying for.
 */
final class SsoReturnTo
{
    /**
     * `sso_auth_requests.return_to` is `varchar(500)`. A destination longer than that is not truncated —
     * a truncated path is a DIFFERENT path, and silently sending someone somewhere else is worse than
     * sending them to the dashboard.
     */
    private const MAX_LENGTH = 500;

    /**
     * The path-and-query to store, or null when there is nothing safe to keep.
     *
     * Null is a perfectly good answer: {@see SsoAuthRequest::$return_to} is nullable and the completion hop
     * falls back to `/dashboard`, which is where P1b's login arm goes unconditionally.
     */
    public static function sanitise(?string $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        // ⚠️ THE SCHEME AND AUTHORITY ARE DISCARDED RATHER THAN REJECTED, AND THE CONSEQUENCE IS WORTH
        // STATING: a foreign absolute URL contributes only its PATH, which then resolves against our own
        // origin. `https://evil.test/steal` becomes `/steal` here, not a refusal.
        //
        // That is deliberate. `Redirect::guest()` stores an ABSOLUTE url, so every legitimate value arrives
        // with our origin on the front of it and rejecting absolutes would empty the column in normal use.
        // Rejecting only FOREIGN absolutes means comparing hosts, and a host comparison is the check that
        // keeps being got wrong. Keeping the path is strictly safe — the result is same-origin by
        // construction — and the only thing it costs is landing a member on a page of OUR app they did not
        // choose, in a case that already requires a foreign `Referer` on a request that passed CSRF.
        $path = parse_url($destination, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $query = parse_url($destination, PHP_URL_QUERY);
        $candidate = is_string($query) && $query !== '' ? $path.'?'.$query : $path;

        return self::isSafePath($candidate) && strlen($candidate) <= self::MAX_LENGTH ? $candidate : null;
    }

    /**
     * The value to actually redirect to — the stored path if it still passes, `/dashboard` otherwise.
     *
     * `/dashboard` rather than `/` because that is the authenticated landing page every other post-auth
     * redirect in the product uses, including the ACS's own login arm.
     */
    public static function destination(?string $stored): string
    {
        return $stored !== null && self::isSafePath($stored) ? $stored : '/dashboard';
    }

    /**
     * Exactly one leading slash, and no character that a browser would read as the start of an authority.
     *
     * `parse_url()` has already removed any scheme, so the remaining risks are the protocol-relative forms
     * and a control character smuggled into a `Location` header.
     */
    private static function isSafePath(string $candidate): bool
    {
        if (! str_starts_with($candidate, '/')) {
            return false;
        }

        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return false;
        }

        // A CR, LF or NUL in a redirect target is header injection; `preg_match` is used rather than
        // `str_contains` so the whole C0 range is covered in one predicate.
        return preg_match('/[\x00-\x1F\x7F]/', $candidate) !== 1;
    }
}
