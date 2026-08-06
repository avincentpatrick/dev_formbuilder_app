<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlatformHost;
use App\Services\Settings\PlatformSettings;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What answers `/` — on the central host AND on every workspace subdomain (Increment I6).
 *
 * ── ONE ROUTE, NOT TWO, AND THE DOMAIN CONSTRAINT IS DELIBERATELY ABSENT ────────────────────────────────
 * The obvious shape is a `Route::domain(central_domain)` on `routes/web.php` plus a tenant `/` in
 * `routes/tenant.php`. It does not work, for two compounding reasons:
 *
 *   1. `routes/web.php` is registered ~70 slots BEFORE anything in `routes/tenant.php` (that file is mapped
 *      from `TenancyServiceProvider` inside `app->booted()`), and this route carries no domain constraint —
 *      so a `Route::get('/')` added to `tenant.php` is unreachable dead code.
 *   2. Adding the constraint here to free it up would then let the tenant route answer a CUSTOM domain,
 *      whose `InitializeTenancyBySubdomain` throws `NotASubdomainException` → a 302 to `app.url`. But
 *      `CustomDomainRoutingTest` pins `/` on a custom host as a **404**, and it is right to: a host the
 *      platform does not own must not learn whether a path exists. `Route::domain(central_domain)` would
 *      also match only the apex, diverging `localhost` from `127.0.0.1` in local dev where both are central.
 *
 * {@see RequirePlatformHost} already permits the central domain and every subdomain of
 * it, and 404s everything else. So branching in here preserves that 404 byte-for-byte, adds no middleware,
 * and needs no second route.
 *
 * ── WHY `$request->user()` IS SAFE HERE WITH NO TENANT CONTEXT ──────────────────────────────────────────
 * This route carries no tenancy middleware, so there is no tenant GUC — and the neighbouring
 * `OpenTenantRegistrationTest` records that a per-tenant question asked in that state fails CLOSED and
 * silently under RLS. Identity is the exception: the `users` provider is `rls_aware`
 * (`config/auth.php`), resolving on the `pgsql_auth` connection precisely so that login, registration and
 * password reset work despite the fail-closed join-shape policy on `users`. Do not add
 * `EstablishTenantDatabaseContext` to this route to "fix" a problem it does not have.
 *
 * A closure would have been shorter and is what lived here before. It is a controller because a route
 * closure makes `php artisan route:cache` throw, and `config/tenancy.php` shows the repo intends to stay
 * cacheable.
 */
final class PlatformLandingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $host = $request->getHost();

        // No subdomain label ⇒ the central host itself (or `localhost` / `127.0.0.1`): the front door.
        if (PlatformHost::subdomainLabel($host) === null) {
            return Inertia::render('Welcome', [
                'appName' => config('app.name'),
                // Gates the "Create workspace" call to action. Without it the front door offers a button
                // that `GateRegistration` 404s whenever platform signup is closed — and the operator who
                // closed it in the admin console would have no way to tell from the page itself.
                'registrationOpen' => app(PlatformSettings::class)->signupOpen(),
                // Read from config rather than from the request, so the page names the CANONICAL workspace
                // host — `127.0.0.1` and `localhost` are both central domains locally, and only one of them
                // is the one a workspace subdomain actually hangs off.
                'centralHost' => (string) config('tenancy.central_domain'),
            ]);
        }

        // A workspace host whose label names no tenant. Send them to the central landing rather than 404 —
        // the same disposition `bootstrap/app.php` already gives an unidentifiable tenant, so a mistyped
        // subdomain behaves one way in the product rather than two.
        if (PlatformHost::tenantFor($host) === null) {
            return redirect()->away((string) config('app.url'));
        }

        // A real workspace. Signed in ⇒ the dashboard; otherwise the sign-in page, which Fortify serves on
        // this same host (its routes carry `'domain' => null`).
        return $request->user() !== null
            ? redirect()->to((string) config('fortify.home'))
            : redirect()->route('login');
    }
}
