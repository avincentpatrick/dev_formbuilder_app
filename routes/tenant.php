<?php

declare(strict_types=1);

use App\Http\Middleware\EstablishTenantDatabaseContext;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant routes (loaded by App\Providers\TenancyServiceProvider::mapRoutes)
|--------------------------------------------------------------------------
| The authenticated app, served on tenant subdomains. Middleware order matters:
|   1. InitializeTenancyBySubdomain — resolve the tenant from the subdomain (binds it).
|   2. PreventAccessFromCentralDomains — 404 tenant routes on the central domain.
|   3. EstablishTenantDatabaseContext — set the RLS session vars (app.current_tenant_id +
|      app.current_user_id) now that both the tenant and the authenticated user are known.
|   4. auth — require a logged-in user (session established centrally; SESSION_DOMAIN spans subdomains).
|
| Spatie's setPermissionsTeamId() is added to EstablishTenantDatabaseContext in Increment B2, when
| roles/permissions land. Real authenticated pages (dashboard, builder, inbox, settings) land inside
| the design-system app shell in Increment C — this is a placeholder to prove the pipeline.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    'auth',
])->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});
