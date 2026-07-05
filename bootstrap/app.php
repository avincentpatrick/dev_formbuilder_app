<?php

use App\Exceptions\Tenancy\MembershipException;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Bridges resolved tenant/user → PostgreSQL RLS session variables (ADR-0002 §D3). Registered
        // as an alias; Increment B attaches it to the authenticated subdomain tenant route group,
        // immediately after stancl/tenancy's identification middleware.
        $middleware->alias([
            'tenant.context' => EstablishTenantDatabaseContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Membership business-rule violations (B2b) are user-facing, not 500s: bounce back with a
        // validation-style error so the form (or the JSON api/* path) surfaces the reason.
        $exceptions->render(fn (MembershipException $e) => back()->withErrors(['membership' => $e->getMessage()]));
    })->create();
