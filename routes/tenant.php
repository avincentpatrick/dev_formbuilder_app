<?php

declare(strict_types=1);

use App\Http\Controllers\Public\GuestFormController;
use App\Http\Controllers\Tenant\FeedbackController;
use App\Http\Controllers\Tenant\FormBuilderController;
use App\Http\Controllers\Tenant\FormController;
use App\Http\Controllers\Tenant\FormPublishController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\MemberController;
use App\Http\Controllers\Tenant\PreferencesController;
use App\Http\Controllers\Tenant\SubmissionController;
use App\Http\Controllers\Tenant\SubmissionInboxController;
use App\Http\Controllers\Tenant\SubmissionReviewController;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\PublicRuntimeSecurityHeaders;
use App\Models\Form;
use App\Models\Submission;
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

    // Forms — the durable form record + draft/publish lifecycle (Increment D3). Authorization is the
    // FormPolicy .any/.own composition, resolved by the `can:<ability>,<model>` middleware. The
    // interactive section/field builder is Increment D4; D3 delivers list + metadata + publish/restore.
    Route::get('/forms', [FormController::class, 'index'])
        ->middleware('can:viewAny,'.Form::class)->name('forms.index');
    Route::post('/forms', [FormController::class, 'store'])
        ->middleware('can:create,'.Form::class)->name('forms.store');
    Route::patch('/forms/{form}', [FormController::class, 'update'])
        ->middleware('can:update,form')->name('forms.update');
    Route::post('/forms/{form}/archive', [FormController::class, 'archive'])
        ->middleware('can:delete,form')->name('forms.archive');
    Route::post('/forms/{form}/publish', [FormPublishController::class, 'store'])
        ->middleware('can:publish,form')->name('forms.publish');
    Route::post('/forms/{form}/versions/{version}/restore', [FormPublishController::class, 'restore'])
        ->middleware('can:update,form')->name('forms.restore');

    // Interactive builder (Increment D4a) — the three-pane workspace + its fine-grained mutation surface.
    // `show` renders the page; the rest are JSON edits the builder's CSRF fetch sidecar calls directly
    // (not Inertia visits, so the client keeps its undo/redo state). All gated by can:update,form; every
    // mutation edits the form's DRAFT version only (the draft_child RLS guard is the DB backstop, and
    // FormBuilderService adds the clean 403). Content edits carry an updated_at token → 409 on drift.
    Route::get('/forms/{form}/builder', [FormBuilderController::class, 'show'])
        ->middleware('can:update,form')->name('forms.builder');
    Route::post('/forms/{form}/sections', [FormBuilderController::class, 'storeSection'])
        ->middleware('can:update,form')->name('forms.sections.store');
    Route::patch('/forms/{form}/sections/{section}', [FormBuilderController::class, 'updateSection'])
        ->middleware('can:update,form')->name('forms.sections.update');
    Route::delete('/forms/{form}/sections/{section}', [FormBuilderController::class, 'destroySection'])
        ->middleware('can:update,form')->name('forms.sections.destroy');
    Route::post('/forms/{form}/fields', [FormBuilderController::class, 'storeField'])
        ->middleware('can:update,form')->name('forms.fields.store');
    Route::patch('/forms/{form}/fields/{field}', [FormBuilderController::class, 'updateField'])
        ->middleware('can:update,form')->name('forms.fields.update');
    Route::delete('/forms/{form}/fields/{field}', [FormBuilderController::class, 'destroyField'])
        ->middleware('can:update,form')->name('forms.fields.destroy');
    Route::post('/forms/{form}/fields/{field}/duplicate', [FormBuilderController::class, 'duplicateField'])
        ->middleware('can:update,form')->name('forms.fields.duplicate');
    Route::post('/forms/{form}/reorder', [FormBuilderController::class, 'reorder'])
        ->middleware('can:update,form')->name('forms.reorder');

    // Manual encoding (Increment F4b) — the first Submission Pipeline channel with a UI. Authorization is
    // SubmissionPolicy::create (submissions.create + per-form collaborator scope + the form is published),
    // resolved by `can:create,<Submission>,form`: the Authorize middleware passes the Submission class-string
    // (which selects the policy) plus the route-bound {form} as the extra policy argument. `store` funnels
    // the raw answers into the single SubmissionPipeline (structural → integrity → semantic → persist).
    Route::get('/forms/{form}/submissions/create', [SubmissionController::class, 'create'])
        // The encode page renders the G5b2 geo control's Leaflet map → scope the OSM tile-origin CSP here
        // (ADR-0006 D3). Only the GET page needs it; the POST store returns a redirect/JSON.
        ->middleware(['can:create,'.Submission::class.',form', PublicRuntimeSecurityHeaders::class])
        ->name('forms.submissions.create');
    Route::post('/forms/{form}/submissions', [SubmissionController::class, 'store'])
        ->middleware('can:create,'.Submission::class.',form')->name('forms.submissions.store');

    // Submissions inbox (Increment F7) — the authenticated read + review + export surface over every pipeline
    // channel. `viewAny`/`view` gate the pages (SubmissionPolicy); row-level visibility (tenant-wide for
    // Owner/Admin/Viewer, own-forms for Editor/Reviewer) is the presenter's `visibleTo` scope. Review
    // transitions gate on submissions.review.*; export is per-form (its columns are form-specific) and gates on
    // submissions.export via the bound {form}. All bind under RLS context (tenancy runs before SubstituteBindings).
    Route::get('/submissions', [SubmissionInboxController::class, 'index'])
        ->middleware('can:viewAny,'.Submission::class)->name('submissions.index');
    Route::get('/submissions/{submission}', [SubmissionInboxController::class, 'show'])
        ->middleware('can:view,submission')->name('submissions.show');
    Route::patch('/submissions/{submission}/review', [SubmissionReviewController::class, 'update'])
        ->middleware('can:review,submission')->name('submissions.review');
    Route::get('/forms/{form}/submissions/export', [SubmissionInboxController::class, 'export'])
        ->middleware('can:export,'.Submission::class.',form')->name('forms.submissions.export');
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

/*
| Guest form runtime — mint (Increment F5). The public share-link entry: resolve the form by its per-tenant
| public_slug (RLS-scoped to the subdomain tenant), gate on guest access + a live published version, and mint
| a stateless HMAC-signed share token. Same subdomain tenant-context pipeline as the invitation group, WITHOUT
| `auth`: guests are not members. Tenant context is resolved from the SUBDOMAIN here (not the token) because
| public_slug is unique only per tenant; the minted token then carries the tenant to the subdomain-less
| /api/v1/public schema + submit endpoints (routes/api.php). Serving the guest SPA shell at this URL is
| Increment F6 — F5 returns the token as JSON.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    // The guest SPA shell hosts the G5b2 geo control's Leaflet map → allow the OSM tile origin (ADR-0006 D3).
    PublicRuntimeSecurityHeaders::class,
])->group(function (): void {
    Route::get('/f/{slug}', [GuestFormController::class, 'mint'])
        ->middleware('throttle:guest-mint')->name('guest.form.mint');
});
