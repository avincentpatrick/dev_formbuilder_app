<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limits the Fortify routes that verify or mint a credential (Increment M43).
 *
 * ⚠️ MOUNTED ON `config/fortify.php`'s `middleware` ARRAY, WHICH APPLIES TO **EVERY** FORTIFY ROUTE — the
 * same mount, and the same first-line guard, as {@see GateRegistration}. Fortify has no per-route
 * middleware hook: one config-level array registers every route it ships, which is why the backlog row
 * that produced this class prescribed *"one `RateLimiter::for()` plus one `->middleware('throttle:…')`"*
 * and the second half had nowhere to land.
 *
 * ── WHAT WAS UNBOUNDED ─────────────────────────────────────────────────────────────────────────────────
 * Fortify ships `throttle:` on four routes only — `POST /login`, `POST /two-factor-challenge` and the two
 * email-verification routes. Everything else that accepts a credential was unmetered, including three
 * routes reachable with no session at all: `POST /forgot-password` (unlimited reset-mail dispatch and
 * account enumeration), `POST /reset-password` (unlimited reset-token guessing) and `POST /register`.
 * The authenticated half included `POST /user/confirm-password` — the redemption door for this app's own
 * step-up gate, {@see RequireRecentPassword} — whose SAML twin has been bounded at 20/min since P1c.
 *
 * ── ⚠️ THE MAP IS KEYED ON ROUTE NAME, AND THAT IS NOT A STYLE CHOICE ───────────────────────────────────
 * Three pairs of Fortify routes share a path across verbs: `POST`/`DELETE /user/two-factor-authentication`,
 * `GET`/`POST /user/confirm-password`, and `GET`/`POST /user/two-factor-recovery-codes`. A path map would
 * need a verb table beside it and would throttle the three `GET` pages by accident. Names separate them for
 * free — and names are the stable half of the vendor API, because every Fortify path is routed through
 * `RoutePath::for()` and is configurable while the name is not.
 *
 * ⛔ **THE WRITE ROUTES CARRY A `.store` SUFFIX AND THE VIEW ROUTES DO NOT.** `register`,
 * `password.confirm` and `two-factor.recovery-codes` are the `GET` pages; the endpoints are
 * `register.store`, `password.confirm.store` and `two-factor.regenerate-recovery-codes`. A map keyed on the
 * obvious-looking name throttles three pages, leaves all three endpoints open, and **every behavioural test
 * still passes**, because the pages are not what anything posts to.
 *
 * ── ⚠️ THE DELEGATION TAKES EXACTLY THREE ARGUMENTS ────────────────────────────────────────────────────
 * {@see ThrottleRequests::handle()} gates its named-limiter path on `func_num_args() === 3`. Passing a
 * fourth argument — a decay, a prefix — silently drops through to the numeric path, where
 * `resolveMaxAttempts()` finds a non-numeric value and throws `MissingRateLimiterException`: a one-token
 * mistake that turns every route below into a 500. Read from the installed 13.18.1 source, not from memory.
 *
 * Delegating rather than counting by hand (the choice {@see EnforceGuestFormRateLimit} makes in the other
 * direction, for a reason that does not apply here) is what keeps the 429, the `Retry-After` header and the
 * `X-RateLimit-*` headers identical to every other throttled route in this application, and keeps
 * `RateLimiter::for()` as the registration surface so the limiters stay assertable the ordinary way.
 * Bucket keys are namespaced by the framework as `md5($limiterName.$limit->key)`, so two limiters here can
 * never share a bucket even when their `by()` keys agree.
 *
 * ── ⚠️ WHY IT IS IN `bootstrap/app.php`'s `priority()` LIST — AND WHY NOT FOR THE OBVIOUS REASON ───────
 * ⛔ **IT IS NOT THERE TO MAKE `$request->user()` RESOLVE. THAT WAS ASSUMED, MEASURED, AND WAS FALSE.**
 * The plan for this increment argued that an unlisted middleware keeps its slot in the Fortify array,
 * ahead of where `auth:web` is spliced, so the user would be null. Measured on the live route table both
 * ways: `SortedMiddleware` hoists the LISTED classes forward past the unlisted ones, so with the entry
 * removed this class lands at index **13 — last, and still after `Authenticate:web` at index 5.**
 * `$request->user()` resolves either way, and a docblock claiming otherwise would be exactly the kind of
 * false statement about a control that stops the next reader looking.
 *
 * What the entry actually buys is **where the refusal happens**: index 6 instead of 13, i.e. ahead of
 * {@see EstablishTenantDatabaseContext} (a database round trip on every request), `SubstituteBindings`
 * and {@see HandleInertiaRequests}. For `POST /forgot-password`, which anyone can reach, capping the work
 * a flood causes is the entire point of the limiter — refusing after paying for tenancy resolution would
 * bound the mail and not the load.
 *
 * The cost, stated rather than left to be discovered: this now runs BEFORE {@see RequirePlatformHost} and
 * {@see GateRegistration}, so a request either of them would answer with 404 still spends a bucket. That
 * is the right way round — those 404s are what a stranger probing subdomains is trying to enumerate.
 *
 * Routes NOT in the map fall straight through, so nothing is throttled twice — `POST /login` keeps
 * `throttle:login` and nothing else, which is the failure mode of the alternative design (a group-wide
 * `throttle:` entry on the config array above).
 */
final class ThrottleFortifyEndpoints
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * Fortify route name => named rate limiter, and the single source of truth for both.
     *
     * ⚠️ READ BY THE TEST SUITE RATHER THAN COPIED INTO IT. A second hand-maintained list of these names
     * would be the paired-artefact hazard of Standing Rule 7(b-bis) reproduced one file later.
     *
     * @return array<string, string>
     */
    public static function limiters(): array
    {
        return [
            // Guest-reachable. No session is needed to reach any of these three.
            'password.email' => 'password-reset-request',
            'password.update' => 'password-reset',
            'register.store' => 'registration',

            // Authenticated, and each one verifies a credential it could otherwise be used to guess.
            'user-password.update' => 'password-update',
            'password.confirm.store' => 'password-confirm',
            'two-factor.confirm' => 'two-factor-confirm',

            // The 2FA lifecycle verbs. Looser: they mutate enrolment rather than test a secret.
            'two-factor.enable' => 'two-factor-manage',
            'two-factor.disable' => 'two-factor-manage',
            'two-factor.regenerate-recovery-codes' => 'two-factor-manage',
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;
        $limiter = $name === null ? null : (self::limiters()[$name] ?? null);

        if ($limiter === null) {
            return $next($request);
        }

        // Exactly three arguments. See the note above; a fourth disables the named-limiter path.
        return $this->throttle->handle($request, $next, $limiter);
    }
}
