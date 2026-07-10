<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the Postgres RLS tenant context for the unauthenticated guest runtime (Increment F5;
 * technical-architecture.md §5 layer 9). The tenant is resolved from the signed `{shareToken}` route
 * segment — NOT from a subdomain or session — so the /api/v1/public surface works cross-origin and
 * embedded, exactly where guests live.
 *
 * The token is verified (signature + expiry) BEFORE any context is set: a forged, tampered, or expired
 * token throws (→ 401, mapped in bootstrap/app.php) and never engages RLS. On success it applies the
 * tenant with a NULL user (guests read no user rows and do no RBAC), so every downstream read/write is
 * RLS-scoped to that tenant — a token minted for tenant A is powerless against tenant B even if app logic
 * were bypassed, because `submissions`/`form_versions` are FORCE ROW LEVEL SECURITY. The decoded token is
 * stashed on the request so the controller can resolve the pinned form/version from its payload.
 *
 * Registered by class-string in routes/api.php (like {@see AuthenticateApiToken}), not as an alias.
 *
 * Ordering note: this middleware does NOT need to precede SubstituteBindings. The only route parameter,
 * `{shareToken}`, is a plain string (not a route-model binding), so nothing downstream of this middleware
 * touches the database except the controller — which always runs after every middleware. This is why the
 * global middleware-priority list in bootstrap/app.php needs no entry for it (unlike AuthenticateApiToken,
 * whose downstream `ability`/`can`/binding consumers forced a precise slot).
 *
 * The session-scoped apply() is paired with a forget() in terminate(), so the tenant context can never
 * survive onto a pooled/persistent connection reused by the next request (ADR-0002 §D2).
 */
final class EstablishGuestTenantContext
{
    public function __construct(private readonly GuestShareTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->verify((string) $request->route('shareToken'));

        // userId stays null: a guest has no account. respondent_user_id is null on the resulting submission.
        TenantContext::apply(tenantId: $token->tenantId);

        $request->attributes->set('guestShareToken', $token);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
