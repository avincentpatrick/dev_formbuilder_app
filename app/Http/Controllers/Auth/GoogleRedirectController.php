<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Auth\GoogleAuthRequestService;
use App\Services\Auth\GoogleSignInGate;
use App\Support\Auth\GoogleIdentityProvider;
use App\Support\Sso\SsoReturnTo;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens a Google sign-in (Increment J3c2, ADR-0017 §D6) — the only route in this flow a person reaches by
 * pressing something.
 *
 * ── ⚠️ ONE ROUTE FOR BOTH HOSTS, WHICH IS FORTIFY'S SHAPE AND NOT A COMPROMISE ───────────────────────
 * The obvious placement is `routes/tenant.php` beside the SAML login path, and it is WRONG here: that file
 * declares no `->domain()`, so a central-host request would match the route and
 * `InitializeTenancyBySubdomain` would throw, while a second registration of the same URI in
 * `routes/google-auth.php` would silently win (it is loaded from `withRouting(then:)`, before
 * `TenancyServiceProvider::mapRoutes()` runs inside `booted()`) and leave the tenant one dead. The user's
 * decision of record is that Google works on tenant hosts AND on the central `/register`, so this takes
 * `/login`'s own pipeline instead — `RequirePlatformHost` with no `Route::domain()`, serving the central
 * host and every tenant subdomain and 404ing exactly one class of host: a custom domain.
 *
 * The consequence is that the tenant comes from the HOST rather than from middleware, exactly as
 * {@see \App\Services\Settings\RegistrationGate::tenantFor()} resolves it for `/register`, and that
 * {@see GoogleAuthRequestService::mint()} has to borrow an RLS context to write its row.
 *
 * ── `return_to` IS SANITISED HERE AND RE-VALIDATED ON THE WAY OUT ───────────────────────────────────
 * {@see SsoReturnTo} stores a PATH and compares no hosts, because a host comparison is the check that
 * keeps being got wrong. On the tenant arm it rides the database row; on the central arm it rides the
 * SESSION, which survives the whole same-host round trip and is unavailable to the other arm precisely
 * because the callback lands on a different host from the tenant page that started it.
 */
final class GoogleRedirectController
{
    /** The session key the central arm parks its destination under, for the length of one consent screen. */
    public const CENTRAL_RETURN_TO = 'google.return_to';

    public function __construct(
        private readonly GoogleSignInGate $gate,
        private readonly GoogleAuthRequestService $requests,
        private readonly GoogleIdentityProvider $google,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        // 404 rather than 403, the non-disclosure posture `GateRegistration` uses on the neighbouring
        // route: an unconfigured deployment says "there is nothing here", not "this exists but is off".
        abort_unless($this->gate->allows($request), 404);

        $tenant = PlatformHost::tenantFor($request->getHost());
        $returnTo = SsoReturnTo::sanitise($request->query('return_to') === null ? null : (string) $request->query('return_to'));

        $state = $this->requests->mint($tenant, $returnTo, $request->ip());

        if ($tenant === null) {
            $request->session()->put(self::CENTRAL_RETURN_TO, $returnTo);
        }

        // `redirect()->away()`: the destination is a third party, so it must never pass through the
        // named-route generator or any same-origin assumption.
        return redirect()->away(
            $this->google->authorizeUrl($state, (string) config('services.google.redirect'))
        );
    }
}
