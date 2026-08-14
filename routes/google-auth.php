<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Middleware\AppSecurityHeaders;
use App\Http\Middleware\EstablishGoogleAuthContext;
use App\Http\Middleware\RequirePlatformHost;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| First-party Google sign-in (Increment J3c2 — ADR-0019)
|--------------------------------------------------------------------------
| Two of the flow's three routes. The third — the completion hop that actually creates the session — is in
| `routes/tenant.php`, because it is the only one that is tenant-only.
|
| ⚠️ NOT the Google Sheets CONNECTOR, whose callback is `routes/connectors.php`. Two different OAuth
| clients, two different consent screens, two different blast radii: this one reads an identity once and
| discards the token; that one holds a durable credential and acts as the tenant inside their spreadsheet.
| ADR-0009's token-custody rules govern that lane and not this one.
|
| ── ⚠️ WHY BOTH ROUTES TAKE FORTIFY'S PIPELINE RATHER THAN THE CONNECTOR ONE ─────────────────────────
| The connector callback group is `Route::domain(config('tenancy.central_domain'))` and sits OUTSIDE `web`.
| Neither carries over, and each divergence is a decision:
|
|   · **`web` IS REQUIRED**, because the CENTRAL arm of this flow signs in at the callback (ADR-0019 §D12)
|     and a sign-in needs a session. The connector callback writes a row and redirects; this one may mint a
|     session, which is the largest side effect the application has. CSRF is not what protects either — the
|     signed `state` is (ADR-0009 §D3) — but a session is not optional here.
|
|   · **NO `Route::domain()`**, diverging from `routes/connectors.php` deliberately. `routes/web.php`
|     records that the constraint does not match `localhost`, and J3c2 must be exercisable against
|     `FakeGoogleIdentityProvider` on a dev box. Fortify's own `'domain' => null` is the precedent, and
|     {@see RequirePlatformHost} is what keeps a tenant's custom domain from serving a platform credential
|     flow — the H22a phishing-surface guard, which is the actual thing a domain constraint would be for.
|
|   · **THE REDIRECT ROUTE SERVES BOTH HOSTS FROM ONE REGISTRATION**, which is `/login`'s shape. The user's
|     decision of record is that Google works on tenant hosts AND on the central `/register`; a route in
|     `routes/tenant.php` cannot serve the second, and registering the same URI in both files would be a
|     silent collision that this file wins (it loads from `withRouting(then:)`, before
|     `TenancyServiceProvider::mapRoutes()` runs inside `booted()`), leaving the tenant copy dead. The
|     controller resolves the workspace from the HOST, exactly as `RegistrationGate` does for `/register`.
|
| ── THE MIDDLEWARE, LINE BY LINE ────────────────────────────────────────────────────────────────────
|   - throttle:google-auth / google-auth-callback — both endpoints are unauthenticated and both do work an
|     anonymous caller can ask for repeatedly (the mint writes a row; the callback talks to Google).
|     ⚠️ SEPARATE BUCKETS: a completed sign-in costs one hit of each, so sharing would halve whichever
|     ceiling an operator thought they were setting.
|   - EstablishGoogleAuthContext — verifies the signed `state` and THEN applies the tenant RLS context from
|     it. Without it the callback's UPDATE would match no policy and affect zero rows without erroring.
|   - AppSecurityHeaders — `frame-ancestors 'none'` on the surfaces that begin and end a sign-in.
|
| `PreventAccessFromCentralDomains` is deliberately absent from both (the callback IS central, and the mint
| must serve the central host too), as is `auth`: nobody is authenticated during a sign-in.
*/

Route::middleware([
    'web',
    RequirePlatformHost::class,
    AppSecurityHeaders::class,
])->group(function (): void {
    Route::get('/auth/google/redirect', GoogleRedirectController::class)
        ->middleware('throttle:google-auth')
        ->name('auth.google.redirect');

    Route::get('/auth/google/callback', GoogleCallbackController::class)
        ->middleware(['throttle:google-auth-callback', EstablishGoogleAuthContext::class])
        ->name('auth.google.callback');
});
