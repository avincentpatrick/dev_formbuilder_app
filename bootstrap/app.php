<?php

use App\Exceptions\Admin\SuperAdminException;
use App\Exceptions\Tenancy\MembershipException;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminMfa;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Membership business-rule violations (B2b) are user-facing, not 500s: bounce back with a
        // validation-style error so the form (or the JSON api/* path) surfaces the reason.
        $exceptions->render(fn (MembershipException $e) => back()->withErrors(['membership' => $e->getMessage()]));

        // Super-admin business-rule violations (B2c) — same posture (e.g. suspending an already-suspended
        // tenant): a user-facing redirect-back-with-error, not a 500.
        $exceptions->render(fn (SuperAdminException $e) => back()->withErrors(['admin' => $e->getMessage()]));
    })->create();
