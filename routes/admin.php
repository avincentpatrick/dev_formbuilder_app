<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\TenantAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super-admin console routes (Increment B2c) — CENTRAL domain only
|--------------------------------------------------------------------------
| The platform-operations console (RBAC §9). Loaded by bootstrap/app.php's withRouting(then:) inside
| the `web` group. Constrained to the single central host (config, not raw env — survives route:cache)
| so it is not served on tenant subdomains. Middleware:
|   - auth        — a logged-in user (session established centrally; SESSION_DOMAIN spans subdomains).
|   - superadmin  — 404 unless the user's global is_super_admin flag is set (non-disclosure).
|   - superadmin.mfa — redirect to enrollment unless the super-admin has confirmed 2FA (security §8).
|
| The console runs with NO tenant context (it is cross-tenant by nature): tenant/suspend/reactivate
| touch the RLS-exempt central `tenants` table on the ordinary connection, and the cross-tenant user
| list reaches `users` only via SuperAdminService's elevated, context-gated connection. Styled with the
| design system in Increment C.
*/
Route::domain((string) config('tenancy.central_domain'))
    ->prefix('admin')
    ->middleware(['auth', 'superadmin'])
    ->group(function (): void {
        // Enrollment landing — deliberately OUTSIDE `superadmin.mfa` so an un-enrolled super-admin can
        // reach it without a redirect loop. It drives Fortify's own global 2FA endpoints.
        Route::get('/two-factor', [TenantAdminController::class, 'mfaSetup'])->name('admin.mfa.setup');

        Route::middleware('superadmin.mfa')->group(function (): void {
            Route::get('/tenants', [TenantAdminController::class, 'index'])->name('admin.tenants.index');
            Route::post('/tenants/{tenant}/suspend', [TenantAdminController::class, 'suspend'])->name('admin.tenants.suspend');
            Route::post('/tenants/{tenant}/reactivate', [TenantAdminController::class, 'reactivate'])->name('admin.tenants.reactivate');
            // Assign (or change) a tenant's plan — admin-assigned, no Cashier (H5a / ADR-0008). The service
            // adopts the affected tenant's context and audits it through the H4 AuditLogger.
            Route::post('/tenants/{tenant}/plan', [TenantAdminController::class, 'assignPlan'])->name('admin.tenants.assign-plan');

            // Cross-tenant user list — exercises the `superadmin_bypass` RLS carve-out via SuperAdminService.
            Route::get('/users', [TenantAdminController::class, 'users'])->name('admin.users.index');
        });
    });
