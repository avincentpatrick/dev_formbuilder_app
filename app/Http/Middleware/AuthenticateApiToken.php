<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum-token authentication for the /api/v1 token-consumed surface (Increment E).
 *
 * Deliberately NOT the `auth:sanctum` alias. `Illuminate\Auth\Middleware\Authenticate` implements
 * AuthenticatesRequests, which the global middleware-priority sorter (bootstrap/app.php) places BEFORE
 * the tenancy pipeline — so the Sanctum token lookup would run with NO RLS tenant context and fail
 * closed against the strict RLS on `personal_access_tokens`. This plain middleware is inserted into the
 * priority array immediately AFTER EstablishTenantDatabaseContext, so the lookup happens with
 * `app.current_tenant_id` already set from the subdomain: the strict SELECT policy reveals the token IFF
 * it was minted for this tenant. Consequences, all enforced by RLS with no app-layer branch:
 *   - a token presented on the wrong tenant subdomain is invisible → 401;
 *   - a removed member's token 401s (its tokenable User is hidden by the `users` join-shape policy);
 *   - deleting a tenant nulls its tokens' tenant_id (nullOnDelete) → they match nothing → 401.
 *
 * On success it backfills `app.current_user_id` (EstablishTenantDatabaseContext left it NULL because the
 * user was unknown pre-auth), so self-scoped reads (the `users` self disjunct, belongs-to-user rows) work.
 * The paired forget() on EstablishTenantDatabaseContext::terminate() clears both GUCs, so this middleware
 * needs no terminate() of its own.
 */
final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Make the sanctum guard the request's default, so $request->user() and the downstream
        // `ability:`/`can:` middleware resolve the token-authenticated user.
        Auth::shouldUse('sanctum');

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        TenantContext::apply(
            tenantId: TenantContext::currentTenantId(),
            userId: (string) $user->getAuthIdentifier(),
        );

        return $next($request);
    }
}
