<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory multi-factor authentication for super-admins (security-threat-model §8 — a hard
 * requirement, not optional hardening, given the account's platform-wide blast radius). A super-admin
 * who has not confirmed two-factor auth (`two_factor_confirmed_at` is null) is redirected to the
 * enrollment page before any console action; a super-admin with confirmed 2FA passes through.
 *
 * This runs AFTER {@see EnsureSuperAdmin}, so the user is always a super-admin here. Enforcement is at
 * console access, not at login — Fortify already TOTP-challenges an *enrolled* super-admin at login, and
 * console-gating protects the sensitive surface without customizing Fortify's auth pipeline. The
 * enrollment landing route (`admin.mfa.setup`) is deliberately OUTSIDE this middleware to avoid a loop.
 */
final class EnsureSuperAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_super_admin === true && $user->two_factor_confirmed_at === null) {
            return redirect()->route('admin.mfa.setup');
        }

        return $next($request);
    }
}
