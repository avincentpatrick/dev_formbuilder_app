<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the super-admin console to platform staff only (RBAC §9): an authenticated user without the
 * global `is_super_admin` flag gets a 404 — NOT a 403 — so the console's very existence is not
 * disclosed to ordinary users (security-threat-model §8: the admin surface should not be discoverable).
 * Guests are handled upstream by the `auth` middleware. `is_super_admin` is a plain global boolean,
 * deliberately never a Spatie role (RBAC §9), so this check does not depend on any tenant context.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin === true, 404);

        return $next($request);
    }
}
