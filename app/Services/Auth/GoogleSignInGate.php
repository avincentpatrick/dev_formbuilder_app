<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Middleware\RequirePlatformHost;
use App\Services\Settings\RegistrationGate;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Http\Request;

/**
 * May this request offer "Continue with Google" (Increment J3c2, ADR-0017)?
 *
 * ── ONE GATE, THREE CONSUMERS, SO THEY CANNOT DISAGREE ─────────────────────────────────────────────────
 * The login page's button, the register page's button, and the redirect route itself all ask this one
 * object. A button that leads to a 404 is the failure {@see RegistrationGate} exists to prevent, and the
 * only way to guarantee it is that no side re-derives the rule.
 *
 * ── ⚠️ IT DELIBERATELY DOES NOT CONSULT `RegistrationGate` ─────────────────────────────────────────────
 * That gate answers "may a STRANGER create an account here", and this button is mostly pressed by people
 * who already have one. On a default workspace (`invite_only` is fail-closed TRUE) Google works for
 * existing members and invited people, and hiding the button there would take it away from exactly them.
 * ADR-0017 §D8 records the accepted cost of the other direction: **the button appears for some people it
 * will refuse**, because whether a given Google account belongs to an existing member is a question the
 * page cannot ask before the person has pressed it. `GoogleSignInProvisioner` asks `RegistrationGate` at
 * the moment it becomes answerable — when a new account or a new membership would actually be created.
 *
 * ── WHY THE HOST CHECK IS HERE EVEN THOUGH IT LOOKS REDUNDANT ─────────────────────────────────────────
 * {@see RequirePlatformHost} already 404s a custom domain on Fortify's group and on the redirect route, so
 * `allows()` is true wherever the two pages actually render today. It is asserted anyway because this
 * object is the thing a future surface will ask, and "the credentials exist" is not the same statement as
 * "this host may serve a platform credential flow" — the H22a phishing-surface argument, which is about
 * hosts rather than about routes.
 */
final class GoogleSignInGate
{
    public function allows(Request $request): bool
    {
        return $this->configured() && PlatformHost::isPlatformHost($request->getHost());
    }

    /**
     * Whether this deployment has a Google OAuth client at all.
     *
     * ⚠️ AN UNCONFIGURED DEPLOYMENT IS A SUPPORTED STATE, NOT A BROKEN ONE. Live Google credentials are an
     * input only the product owner can supply, so the whole feature has to be absent-by-default: the button
     * does not render, the routes 404, and every behaviour behind them is still exercised in tests against
     * `FakeGoogleIdentityProvider`. `config/services.php` says the same thing from the other side.
     */
    public function configured(): bool
    {
        $id = config('services.google.client_id');
        $secret = config('services.google.client_secret');

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }
}
