<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Who is REALLY at the keyboard, when that is not who the action runs as — Increment I11a.
 *
 * `docs/multi-tenancy-rbac-design.md` §9:433 states exactly one requirement about impersonation: keep "a
 * super-admin's own actions distinguishable from actions taken while impersonating". This class is the
 * ambient half of meeting it; `audits.acting_as_user_id` is the stored half.
 *
 * ── WHY AMBIENT AND NOT A PARAMETER, WHICH IS THE INTERESTING DECISION ──────────────────────────────────
 * {@see AuditLogger::record()} already takes nine parameters and is called from roughly fifteen sites. A
 * tenth is a thing to forget at every one of them, and forgetting it is SILENT: the row still writes, still
 * looks well-formed, and simply omits the fact that platform staff were driving. That is the exact defect
 * the threat model names at :144 — "impersonation without a distinguishing audit column would make a
 * compromised super-admin session indistinguishable from the impersonated user's own actions". A guarantee
 * that has to be remembered fifteen times is not a guarantee.
 *
 * So the logger reads it the same way it already reads the request IP and user agent: ambiently, once, at
 * the single write path. Every existing call site gains the behaviour without being touched, and — more to
 * the point — every FUTURE call site does too.
 *
 * ── THE SESSION IS THE SOURCE OF TRUTH, THE STATIC IS AN OVERRIDE ───────────────────────────────────────
 * An impersonated session is a real logged-in session on the tenant host that carries {@see SESSION_KEY};
 * I11b is what writes it. Reading straight from the session means no middleware has to remember to mirror
 * it into PHP state, and a request that somehow bypasses that middleware still audits correctly.
 *
 * The static exists for the two contexts that have no session at all — queued jobs and console commands,
 * where an impersonated action's follow-up work would otherwise lose the attribution — and as the test
 * seam. It takes precedence when set, so a caller can pin an operator explicitly; {@see forget()} restores
 * session-derived behaviour. ⚠️ Pair every {@see set()} with a {@see forget()} in a `finally`, for the
 * reason {@see TenantContext} spells out about its own session-scoped setter: a static
 * that survives into the next job on a long-lived worker would attribute an unrelated action to an operator
 * who had gone home.
 *
 * ── NOT A REPLACEMENT FOR `user_id` ─────────────────────────────────────────────────────────────────────
 * During impersonation `user_id` remains the EFFECTIVE actor (the impersonated user, whose authority the
 * action actually ran under) and this is the additional one. The migration argues why that direction, and
 * not the reverse, is the only one that leaves every existing reader still telling the truth.
 */
final class ImpersonationContext
{
    /**
     * The session key an impersonated session carries. Declared here rather than in the middleware that
     * writes it so the writer (I11b) and the reader (the audit logger) cannot drift apart on a string.
     */
    public const string SESSION_KEY = 'impersonator_id';

    private static ?string $operatorId = null;

    /** The real operator's id, or null when nobody is impersonating. Always a uuid or null. */
    public static function operatorId(): ?string
    {
        return self::$operatorId ?? self::fromSession();
    }

    /** Pin an operator for a context that has no session (queue, console) or for a test. */
    public static function set(?string $operatorId): void
    {
        self::$operatorId = self::sanitise($operatorId);
    }

    /** Drop the pin and fall back to the session. Call this in a `finally`. */
    public static function forget(): void
    {
        self::$operatorId = null;
    }

    /**
     * Best-effort session read — null outside an HTTP context, mirroring how {@see AuditLogger} guards its
     * own request-metadata reads.
     *
     * ⚠️ TWO SOURCES, AND THE SECOND IS NOT BELT-AND-BRACES. `request()->hasSession()` is only true once
     * `StartSession` has attached the store to the request instance, which is the real HTTP path — but a
     * feature test that seeds state with Laravel's `session()` helper writes to the session MANAGER without
     * that attachment, so a request-only read returns null and every session-driven test silently proves
     * nothing. The first draft did exactly that and its new case failed on the first run.
     *
     * ⚠️ AND NO `isStarted()` GUARD, WHICH WAS THE SECOND WRONG GUESS. It reads false in precisely the
     * context the fallback exists for — measured: after `session([...])` the store returns the value while
     * `isStarted()` is still false — so gating on it reinstates the bug it was meant to avoid. The guard is
     * unnecessary anyway: `Store::get()` reads an attribute bag that is `[]` until something populates it,
     * so on a console or queue process this returns null without touching a driver.
     */
    private static function fromSession(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        if (request()->hasSession()) {
            return self::sanitise(request()->session()->get(self::SESSION_KEY));
        }

        // ⚠️ THE `bound('request')` GUARD ABOVE IS LOAD-BEARING, AND REMOVING IT COST TWELVE FAILURES.
        // Resolving the session MANAGER is not free the way reading an already-attached store is: it
        // instantiates a driver, and this method runs on EVERY audit write — including inside queued jobs
        // and scheduled sweeps, which have no request at all. A version that reached for the manager
        // unconditionally turned five unrelated sweep/reaper/seeder suites red in CI. Confining the
        // fallback to request-bound contexts keeps the test path working (a feature test seeding via the
        // `session()` helper is request-bound) while leaving console and queue exactly as they were.
        return app()->bound('session')
            ? self::sanitise(app('session')->driver()->get(self::SESSION_KEY))
            : null;
    }

    /**
     * ⚠️ EVERY PATH IN IS UUID-VALIDATED, AND THE COST OF SKIPPING IT IS NOT A BAD AUDIT ROW — IT IS THE
     * CALLER'S TRANSACTION.
     *
     * This value lands in a `uuid` column via `AuditLogger::record()`, which runs INSIDE the business
     * transaction it is recording. A non-uuid string reaches PostgreSQL as `SQLSTATE 22P02`, which nothing
     * catches: the audit INSERT throws, the enclosing transaction rolls back, and the form save or
     * membership change that triggered it is silently undone. A syntactically valid uuid with no matching
     * user is `23503` with the same blast radius — reachable for real if an operator's account is deleted
     * while their impersonated session is live, since `ON DELETE SET NULL` repairs existing rows but does
     * nothing for the next INSERT.
     *
     * Failing CLOSED (attributing nothing) is the right trade against failing the user's actual write. The
     * read side already learned this: `PlatformAuditFilterRequest` validates the operator filter as a uuid
     * so the console does not 500 on a cast. This is the write-side equivalent, which the first draft
     * lacked entirely.
     */
    private static function sanitise(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
