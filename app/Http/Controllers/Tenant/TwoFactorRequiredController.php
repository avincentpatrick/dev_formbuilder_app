<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\User;
use App\Support\Auth\PasswordConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The 2FA enrollment interstitial (Increment I8a, PRD Feature #14) — where
 * {@see EnforceTenantTwoFactor} sends a member of a workspace that requires two-factor.
 *
 * The tenant twin of {@see TenantAdminController::mfaSetup()}, and it reuses
 * the same `TwoFactorSetup` component, so there is one enrollment UI in the product rather than two that
 * drift. `needsPasswordConfirmation` matters here for the same reason it does there: Fortify's QR and
 * recovery-code endpoints answer a JSON sidecar with a 423 rather than a redirect, and a blank QR on a
 * page nobody can navigate away from is a lockout.
 *
 * ⚠️ REDIRECTS AWAY ONCE ENROLLMENT IS COMPLETE. Without that, confirming the TOTP leaves the user on a
 * page telling them to do the thing they have just done — the middleware would let them anywhere now, but
 * nothing would take them there, and the only affordance on screen is "sign out".
 */
final class TwoFactorRequiredController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/TwoFactorRequired', [
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => false,
            'needsPasswordConfirmation' => PasswordConfirmation::isStale($request),
        ]);
    }
}
