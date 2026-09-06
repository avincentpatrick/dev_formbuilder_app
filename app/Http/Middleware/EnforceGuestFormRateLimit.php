<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ResolvesGuestForm;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PER-FORM guest rate limit (Increment I8b) — the "configurable rate limiting" half of PRD Feature
 * #3's last acceptance criterion. Layered ON TOP of the deployment-wide `throttle:guest` limiters, never
 * replacing them.
 *
 * ── ⚠️ WHY THIS IS A MIDDLEWARE AND NOT A `RateLimiter::for()` CLOSURE ────────────────────────────────
 * The obvious implementation is to teach the existing `guest` limiter about the form. It cannot work.
 * `bootstrap/app.php`'s priority list puts `ThrottleRequests` ahead of the tenancy classes, while
 * {@see EstablishGuestTenantContext} is not in that list at all — so the throttle runs BEFORE any tenant
 * GUC is set, and `forms` is FORCE ROW LEVEL SECURITY. The read would return zero rows, and the dial
 * would silently do nothing. Setting the GUC inside a limiter closure would duplicate the context
 * middleware, break its deliberate verify-then-apply ordering, and leave no `terminate()` to pair the
 * `forget()` with (ADR-0002 §D2).
 *
 * ⛔ THE ORDINAL THIS SENTENCE USED TO CARRY WAS WRONG, AND IT WAS THE COMMIT NEXT DOOR THAT BROKE IT (M78).
 * It read *"puts `ThrottleRequests` at index 6"*, which was true when it was written (2026-08-08). `M43`
 * then inserted `ThrottleFortifyEndpoints` into that exact slot, moving `ThrottleRequests` to 7 — so the
 * number rotted without this file being touched, which is the whole case against writing one. The claim
 * that carries the argument is the RELATION (throttle before tenancy), and the relation never moved.
 * `config/fortify.php` already documents the same ordering prose-only for the same reason; follow that,
 * not the ordinal. ⚠️ A backlog row filed against the two `M43` comments alleging they were off by one was
 * REFUTED — 5, 6 and 13 are all correct there — because `route:list --json` never expands the `web` group
 * and so cannot be read as an execution order. `TenancyMiddlewarePriorityTest:140` says so at length.
 *
 * So the cheap, DB-free velocity ceiling stays where it is — rejecting a flood before it costs a query —
 * and the per-form dial runs here, after context. Attached PER-ROUTE rather than to the group: route
 * middleware is appended after group middleware and neither this class nor
 * {@see VerifyGuestBotChallenge} is in the priority list, so both land after
 * {@see EstablishGuestTenantContext} structurally rather than by convention.
 *
 * ── ⚠️ PER-IP WITHIN THIS FORM, NEVER FORM-WIDE ───────────────────────────────────────────────────────
 * A form-wide bucket (all IPs against one counter) sounds like the more powerful dial and is the one that
 * superficially matches "curb spam on this form". **It is a self-DoS lever**: an attacker with a single IP
 * saturates it at a cost of N requests per minute and the form stops accepting anyone, which converts an
 * annoyance into an outage. Handing an author a control whose primary effect under attack is denying
 * service to real respondents is worse than not shipping the control.
 *
 * The low-and-slow distributed case a form-wide bucket would notionally catch — many IPs, many tokens,
 * nobody individually fast — is the CHALLENGE's job, which is the division of labour
 * docs/security-threat-model.md §4 already draws.
 *
 * ── The failure response costs nothing new ────────────────────────────────────────────────────────────
 * {@see ThrottleRequestsException} is already rendered as `429 rate_limited` with headers by
 * bootstrap/app.php; `error-normalizer.ts` already classifies 429; `RuntimeSession.handleSubmitError`
 * already shows the notice; and `replay.ts` already backs off and keeps the row pending. Nothing
 * downstream of this class needed a line.
 */
final class EnforceGuestFormRateLimit
{
    use ResolvesGuestForm;

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->guestForm($request)->guest_rate_limit_per_minute;

        // NULL is "no per-form ceiling", and it is the branch every form created before I8b takes. Note
        // this is deliberately not `!$limit`: 0 would mean "accept nothing", which an author cannot set
        // today (the request enforces min:1) but which must not silently mean "unlimited" if it ever can.
        if ($limit === null) {
            return $next($request);
        }

        $key = 'gform:'.$this->guestForm($request)->getKey().':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ThrottleRequestsException(
                'Too Many Attempts.',
                null,
                ['Retry-After' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
