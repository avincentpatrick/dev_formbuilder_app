<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\FeedbackConsoleController;
use App\Http\Controllers\Admin\PlatformAuditController;
use App\Http\Controllers\Admin\PlatformSettingsController;
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
|   - step-up (I8a)  — PRD Feature #14 names "the super-admin console" as a high-blast-radius surface, so
|                      a live session is not enough: the operator must have confirmed their password in
|                      the last 15 minutes (auth.step_up_timeout). ⚠️ ITS POSITION IS LOAD-BEARING — it is
|                      INSIDE the superadmin.mfa group, never ahead of it, or an un-enrolled operator
|                      confirms a password and is then bounced to enrollment having gained nothing.
|                      GET /admin/two-factor stays outside BOTH, for the same anti-loop reason.
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

        Route::middleware(['superadmin.mfa', 'step-up'])->group(function (): void {
            Route::get('/tenants', [TenantAdminController::class, 'index'])->name('admin.tenants.index');
            // One workspace in depth (I7b): plan + usage + domains. ⚠️ `whereUuid` is not decoration —
            // `tenants.id` is a uuid column, so an unbound `/admin/tenants/not-a-uuid` reaches Postgres as
            // `where id = 'not-a-uuid'` and raises SQLSTATE 22P02, i.e. a 500 rather than a 404. The three
            // POST routes below share that latent defect and have simply never been reachable from a UI.
            Route::get('/tenants/{tenant}', [TenantAdminController::class, 'show'])
                ->whereUuid('tenant')->name('admin.tenants.show');
            Route::post('/tenants/{tenant}/suspend', [TenantAdminController::class, 'suspend'])->name('admin.tenants.suspend');
            Route::post('/tenants/{tenant}/reactivate', [TenantAdminController::class, 'reactivate'])->name('admin.tenants.reactivate');
            // Assign (or change) a tenant's plan — admin-assigned, no Cashier (H5a / ADR-0008). The service
            // adopts the affected tenant's context and audits it through the H4 AuditLogger.
            Route::post('/tenants/{tenant}/plan', [TenantAdminController::class, 'assignPlan'])->name('admin.tenants.assign-plan');

            // Cross-tenant user list — exercises the `superadmin_bypass` RLS carve-out via SuperAdminService.
            Route::get('/users', [TenantAdminController::class, 'users'])->name('admin.users.index');

            // Feedback support console (I7a, PRD Feature #11 / RBAC §9 review queue). The `{feedback}`
            // parameter is a RAW UUID, never a bound model: binding resolves on the app connection, which
            // has no tenant context here, so RLS would 404 every valid id. See the controller's docblock.
            Route::get('/feedback', [FeedbackConsoleController::class, 'index'])->name('admin.feedback.index');
            Route::patch('/feedback/{feedback}', [FeedbackConsoleController::class, 'update'])
                ->whereUuid('feedback')->name('admin.feedback.update');
            Route::get('/feedback/{feedback}/screenshot', [FeedbackConsoleController::class, 'screenshot'])
                ->whereUuid('feedback')->name('admin.feedback.screenshot');

            // The PLATFORM audit ledger (I7b, PRD Feature #12 / RBAC §9). Reads `audits` rows with
            // `tenant_id IS NULL` ONLY — the rows /admin/settings writes — through a narrowed SELECT policy
            // (`audits_platform_select`). A super-admin action against a SPECIFIC tenant is deliberately
            // not here: it lands in that tenant's own /audit-log, which is RBAC §9's transparency posture,
            // and the page says so in as many words. No {audit} detail route (the diff is a modal, matching
            // routes/tenant.php's call) and no export — see the controller docblock.
            Route::get('/audit-log', [PlatformAuditController::class, 'index'])->name('admin.audit-log.index');

            // Platform settings (I5, PRD Feature #10) — open-signup and platform maintenance, the two
            // toggles that belong to no tenant. The WRITE is the console's first operation that needs the
            // elevated connection to write rather than to read: `settings` keeps strict INSERT/UPDATE
            // policies, so a NULL-tenant row is unwritable from the app connection (silently — it affects
            // zero rows). See SuperAdminService::updatePlatformSettings().
            //
            // ⚠️ THIS PREFIX IS EXEMPT FROM PLATFORM MAINTENANCE ITSELF (EnforcePlatformMaintenance's
            // `admin/*` path exemption). Without that, switching maintenance ON would lock the operator out
            // of the only page that can switch it off.
            Route::get('/settings', [PlatformSettingsController::class, 'index'])->name('admin.settings.index');
            Route::patch('/settings', [PlatformSettingsController::class, 'update'])->name('admin.settings.update');
        });
    });
