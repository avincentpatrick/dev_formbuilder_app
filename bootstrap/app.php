<?php

use App\Exceptions\Admin\SuperAdminException;
use App\Exceptions\Tenancy\MembershipException;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminMfa;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The super-admin console (Increment B2c) — a central-domain-only group, not the tenant
        // subdomain group (that is mapped by TenancyServiceProvider). Loaded inside the `web` group.
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Bridges resolved tenant/user → PostgreSQL RLS session variables (ADR-0002 §D3). Registered
        // as an alias; Increment B attaches it to the authenticated subdomain tenant route group,
        // immediately after stancl/tenancy's identification middleware.
        //
        // superadmin / superadmin.mfa (B2c) gate the central-domain console: is_super_admin flag +
        // mandatory confirmed 2FA (security-threat-model §8).
        $middleware->alias([
            'tenant.context' => EstablishTenantDatabaseContext::class,
            'superadmin' => EnsureSuperAdmin::class,
            'superadmin.mfa' => EnsureSuperAdminMfa::class,
        ]);

        // Middleware ordering (ADR-0002 §D3). The tenancy pipeline must ESTABLISH the RLS session
        // context BEFORE SubstituteBindings runs, or a route-bound tenant-scoped model (e.g.
        // /forms/{form}) is resolved with no tenant context and the RLS-scoped lookup 404s. The three
        // tenancy middleware are inserted after Authenticate (so EstablishTenantDatabaseContext can read
        // the authenticated user for app.current_user_id) and before SubstituteBindings. Priority only
        // reorders middleware a route already has — central/non-tenant routes are unaffected.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            InitializeTenancyBySubdomain::class,
            PreventAccessFromCentralDomains::class,
            EstablishTenantDatabaseContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render framework exceptions (validation, auth, model-not-found) as JSON for the JSON API surface
        // AND for any request that explicitly expects JSON — the builder's CSRF fetch sidecar (D4a) sends
        // Accept: application/json and needs a 422 body, not the HTML validation redirect Inertia visits get
        // (Inertia requests do not expectsJson(), so their redirect-with-errors flow is unaffected).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Membership business-rule violations (B2b) are user-facing, not 500s: bounce back with a
        // validation-style error so the form (or the JSON api/* path) surfaces the reason.
        $exceptions->render(fn (MembershipException $e) => back()->withErrors(['membership' => $e->getMessage()]));

        // Super-admin business-rule violations (B2c) — same posture (e.g. suspending an already-suspended
        // tenant): a user-facing redirect-back-with-error, not a 500.
        $exceptions->render(fn (SuperAdminException $e) => back()->withErrors(['admin' => $e->getMessage()]));
    })->create();
