<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\FeedbackController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\MemberController;
use App\Http\Controllers\Tenant\PreferencesController;
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
| EstablishTenantDatabaseContext also sets Spatie's permissions team id to this tenant (Increment B2a),
| so RBAC resolves roles against the same tenant RLS isolates by. Real authenticated pages (dashboard,
| builder, inbox, settings) land inside the design-system app shell in Increment C — this is a
| placeholder to prove the pipeline.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    'auth',
])->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    // Settings — the current theme reaches the page via the shared `ui.theme` prop (no controller
    // needed for the read). The appearance write persists theme_mode to user_ui_preferences; it lives
    // here (not central) so app.current_user_id is set and the belongs-to-user RLS write succeeds.
    Route::get('/settings', [PreferencesController::class, 'show'])->name('settings');
    Route::patch('/settings/appearance', [PreferencesController::class, 'updateTheme'])
        ->name('settings.appearance.update');

    // Member administration (Owner/Admin) — authorization is the Spatie permission on each route
    // (B2b). Owner is never invitable; it changes hands only via the ownership-transfer route (§5, §7).
    // The roster page is gated on the same manage ability (the 27-permission catalog is closed — there
    // is no separate members.view — so viewing the roster is an Owner/Admin management surface).
    Route::get('/members', [MemberController::class, 'index'])
        ->middleware('can:tenant.members.invite')->name('members.index');
    Route::post('/members/invitations', [MemberController::class, 'invite'])
        ->middleware('can:tenant.members.invite')->name('members.invite');
    Route::delete('/members/{user}', [MemberController::class, 'remove'])
        ->middleware('can:tenant.members.remove')->name('members.remove');
    Route::post('/members/ownership', [MemberController::class, 'transferOwnership'])
        ->middleware('can:tenant.ownership.transfer')->name('members.ownership');

    // In-app feedback (Feature #11) — every role may submit (can:feedback.submit). Screenshot capture
    // and the cross-tenant support console are Phase 1 (need the attachments table + elevated read).
    Route::post('/feedback', [FeedbackController::class, 'store'])
        ->middleware('can:feedback.submit')->name('feedback.store');
});

/*
| Invitation accept/decline — the invitee side (B2b). Same subdomain tenant-context pipeline as above
| but WITHOUT `auth`: the invitee is not a member yet, so requiring auth would be circular. Tenant
| context is still established, which is exactly what makes the strict-RLS invite row visible (only
| within its own tenant) and lets accept materialize the reserved role. Styled pages land in Increment C.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
])->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('/invitations/{token}', [InvitationController::class, 'decline'])->name('invitations.decline');
});
