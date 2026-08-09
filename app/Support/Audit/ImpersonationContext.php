<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;

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

    /** The real operator's id, or null when nobody is impersonating. */
    public static function operatorId(): ?string
    {
        return self::$operatorId ?? self::fromSession();
    }

    /** Pin an operator for a context that has no session (queue, console) or for a test. */
    public static function set(?string $operatorId): void
    {
        self::$operatorId = $operatorId;
    }

    /** Drop the pin and fall back to the session. Call this in a `finally`. */
    public static function forget(): void
    {
        self::$operatorId = null;
    }

    /**
     * Best-effort session read — null outside an HTTP context, mirroring how {@see AuditLogger} guards its
     * own request-metadata reads. `app()->bound('session')` is not enough on its own: the session service
     * resolves in console contexts too but has no started store, and calling `get()` on it throws.
     */
    private static function fromSession(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $session = request()->hasSession() ? request()->session() : null;

        $value = $session?->get(self::SESSION_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
