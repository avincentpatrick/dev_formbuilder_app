<?php

declare(strict_types=1);

use App\Http\Controllers\Connectors\ConnectorCallbackController;
use App\Http\Middleware\EstablishConnectorOauthContext;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Native-connector OAuth callbacks (H15a — ADR-0009) — CENTRAL domain only
|--------------------------------------------------------------------------
| Where every provider redirects after a tenant authorizes our app. Loaded by bootstrap/app.php's
| withRouting(then:) WITHOUT wrapping middleware, so this group owns its full pipeline — the routes/api.php
| convention, not the routes/admin.php one, and for a specific reason: this group must NOT be inside `web`.
|
| Why the central domain (ADR-0009 §D2): an OAuth app registers ONE fixed redirect URI and providers reject
| wildcard hosts, so a per-tenant subdomain callback is unshippable past the first tenant. Constrained to
| config('tenancy.central_domain') — config, not raw env(), so it survives route:cache.
|
| Why no `web` (§D3): the inbound request is a third-party GET from a consent screen. It carries no session
| (the session cookie is host-only, so the tenant's session is not readable here) and no CSRF token, and
| demanding either would break the flow rather than secure it. The CSRF control for this hop is the signed
| `state` parameter — which is the role OAuth itself assigns it.
|
| Middleware:
|   - throttle:connector-oauth        — an unauthenticated public endpoint; bounded per IP.
|   - EstablishConnectorOauthContext  — verifies `state` (signature, claims, provider binding, expiry) and
|     THEN applies the tenant + user RLS context from it. Without this the callback's INSERT would match no
|     RLS policy and write zero rows without erroring.
|   - SubstituteBindings              — nothing is route-model-bound here, but keeping the pipeline explicit
|     matches the other self-declaring groups.
|
| PreventAccessFromCentralDomains is deliberately absent (this IS the central domain), as is auth: the
| acting user is proven by the signed state, not by a session.
*/
Route::domain((string) config('tenancy.central_domain'))
    ->middleware([
        'throttle:connector-oauth',
        EstablishConnectorOauthContext::class,
        SubstituteBindings::class,
    ])
    ->group(function (): void {
        Route::get('/oauth/{provider}/callback', ConnectorCallbackController::class)
            ->name('connectors.callback');
    });
