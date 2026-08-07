<?php

declare(strict_types=1);

use App\Http\Controllers\Public\GuestFormController;
use App\Http\Controllers\Public\PwaManifestController;
use App\Http\Controllers\Public\ServiceWorkerController;
use App\Http\Controllers\Tenant\AnalyticsController;
use App\Http\Controllers\Tenant\AnalyticsViewController;
use App\Http\Controllers\Tenant\AttachmentController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\BrandingController;
use App\Http\Controllers\Tenant\BrandingLogoController;
use App\Http\Controllers\Tenant\ConnectionController;
use App\Http\Controllers\Tenant\ConnectionRuleController;
use App\Http\Controllers\Tenant\ConnectorAuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DomainController;
use App\Http\Controllers\Tenant\FeedbackController;
use App\Http\Controllers\Tenant\FormBuilderController;
use App\Http\Controllers\Tenant\FormConfirmationMessageController;
use App\Http\Controllers\Tenant\FormController;
use App\Http\Controllers\Tenant\FormPublishController;
use App\Http\Controllers\Tenant\FormSaveResumeController;
use App\Http\Controllers\Tenant\FormScheduleController;
use App\Http\Controllers\Tenant\FormScopeController;
use App\Http\Controllers\Tenant\FormShareController;
use App\Http\Controllers\Tenant\FormShareQrController;
use App\Http\Controllers\Tenant\FormTemplateController;
use App\Http\Controllers\Tenant\FormXlsformController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\MemberController;
use App\Http\Controllers\Tenant\NotificationController;
use App\Http\Controllers\Tenant\PreferencesController;
use App\Http\Controllers\Tenant\ResourceGrantController;
use App\Http\Controllers\Tenant\ScopeNodeController;
use App\Http\Controllers\Tenant\SubmissionController;
use App\Http\Controllers\Tenant\SubmissionInboxController;
use App\Http\Controllers\Tenant\SubmissionReviewController;
use App\Http\Controllers\Tenant\TenantSettingsController;
use App\Http\Controllers\Tenant\TwoFactorRequiredController;
use App\Http\Controllers\Tenant\WebhookController;
use App\Http\Middleware\AppSecurityHeaders;
use App\Http\Middleware\EnforceTenantMaintenance;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Http\Middleware\PublicRuntimeSecurityHeaders;
use App\Models\Audit;
use App\Models\Connection;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Route;
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
|   4. auth — require a logged-in user.
|
| Correction (H22a, from ADR-0012's PreventAccessFromCentralDomains/central_domains audit): step 4 used to
| read "session established centrally; SESSION_DOMAIN spans subdomains". That was never true here.
| SESSION_DOMAIN is null in both .env and .env.example, so the session cookie is HOST-ONLY — a login on
| acme.meridian.test is not visible on beta.meridian.test. It works because Fortify's routes carry no domain
| constraint, so a user logs in on the tenant host itself, not because a cookie spans hosts. That property is
| what makes the guest group below safe to serve on a second host: there is no ambient authenticated session
| to carry across origins.
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
    // AppSecurityHeaders (I1) — frame-ancestors 'none' + nosniff + Referrer-Policy on every authenticated
    // page. Named here rather than globally on `web` on purpose: the guest runtime below also uses `web`,
    // and a global mount would set frame-ancestors 'none' on it and break every embed in the product.
    AppSecurityHeaders::class,
    // EnforceTenantTwoFactor (I8a) — PRD Feature #14's org-level enforcement policy. LAST in the array
    // deliberately: it reads a tenant-scoped setting, so EstablishTenantDatabaseContext must already have
    // set the RLS GUC, and `auth` must already have resolved the user. The enrollment interstitial it
    // redirects to is registered in its OWN group below, outside this one — see that group's header for
    // why the exemption is structural rather than a path allow-list.
    EnforceTenantTwoFactor::class,
])->group(function (): void {
    // The authenticated landing page (H11) — real, visibility-scoped KPI counts from DashboardMetricsService.
    // No `can:` gate: every role lands here after login; the per-role scoping is the service's job.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    | The notification centre (Increment I4, PRD Feature #13b) — a BELL, not a page. There is deliberately
    | no /notifications Inertia page: the popover shows the latest rows and every deep link goes to the
    | thing itself, so a full-page list would be a second surface with no second job.
    |
    | ⚠️ ALL THREE ROUTES ARE JSON, AND BOTH HALVES OF THAT ARE DELIBERATE.
    |
    | The READ is a sidecar (the /scopes/{n}/impact + /integrations/.../channels precedent), polled on ~60s
    | and refetched on Inertia navigation. It is NOT an Inertia::optional() shared prop, which is what the
    | I-map originally called for: a partial reload RE-DISPATCHES THE CURRENT PAGE'S CONTROLLER — Inertia
    | filters what it SERIALIZES, not what it COMPUTES — so a tick with /audit-log open would pay for a full
    | ledger paginate plus a count(*) and throw the result away (AuditLogPresenter's docblock accepts that
    | cost once per navigation, not once per minute). Nothing about notifications enters
    | HandleInertiaRequests::share(), which renders on every page in the application.
    |
    | The two WRITES are also JSON, which is a NAMED EXCEPTION to the rule scopesClient/integrationsClient
    | state ("every mutation on this surface is an Inertia visit"). That rule exists because bootstrap/app.php
    | keys its domain-exception branch on the `api/v1/*` PATH, so a DomainException raised on a web route
    | 302s even to a fetch expecting JSON. Neither route here raises one — they stamp a timestamp on a row
    | the caller owns — and framework exceptions already negotiate (bootstrap/app.php's
    | shouldRenderJsonWhen covers 401/403/404/419). The reason it MUST be a fetch is Inertia's own request
    | stream: it is `maxConcurrent: 1, interruptible: true`, so a router.post() fired in the same tick as
    | the row's <Link> navigation is silently ABORTED — click-to-read is unimplementable as an Inertia visit
    | without a wasted second round trip. A 204 also avoids re-rendering the page under the user on every
    | click, which back() would do.
    |
    | ⚠️ NO `can:` GATE ON THE COLLECTION AND NO `feature:` GATE ANYWHERE, AND BOTH ABSENCES ARE DELIBERATE.
    |  · No permission: the RBAC catalog is closed, and a notification is addressed to ONE person by
    |    notifications.user_id — so "may I read this?" is answered by Notification::scopeForUser(), never by
    |    a role. Coining `notifications.view` would invent a permission whose audience is "every
    |    authenticated user" (the closed-catalog argument AuditLogPresenter makes for `audit_log.export`).
    |    This is PATCH /settings/appearance's posture: a user reads and edits only their own account.
    |  · No plan feature: PlanCatalog defines no notifications key on any tier, and PRD Feature #13's own
    |    acceptance criterion is that notifications are "available on EVERY tier — a Free tenant with no
    |    webhook access still gets submission notifications". Adding one would be MAKING a pricing decision
    |    rather than enforcing one (the /audit-log "baseline obligation, not an upsell" argument).
    |    NotificationRouteGuardsTest asserts both, so neither can be "tidied" into symmetry with the
    |    neighbours below.
    |
    | The per-row write DOES carry a gate, on the BOUND INSTANCE: `notifications` is strict RLS, so binding
    | resolves any co-tenant's row and NotificationPolicy::markRead is the only thing standing between a
    | member and a colleague's read state. Same shape, same reason, as analytics.views.update below.
    |
    | Static segment before any binding (the H14 rule): /notifications/read-all is declared BEFORE
    | /notifications/{notification}/read, or the bare binding would capture "read-all" as a uuid.
    */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->middleware('can:markRead,notification')->name('notifications.read');

    // Settings — the current appearance reaches the page via the shared `ui.theme` prop (no controller
    // needed for the read). The appearance write persists the four personalization axes to
    // user_ui_preferences; it lives here (not central) so app.current_user_id is set and the
    // belongs-to-user RLS write succeeds. Partial by design — see UpdateAppearanceRequest.
    Route::get('/settings', [PreferencesController::class, 'show'])->name('settings');
    Route::patch('/settings/appearance', [PreferencesController::class, 'updateAppearance'])
        ->name('settings.appearance.update');
    // Per-user notification preferences (I4, data-dictionary §23) — the same posture as the appearance
    // write directly above, and for the same reason: a user edits only their own account, and
    // notification_preferences is strict-RLS keyed on user_id. ONE type per request, BOTH booleans always
    // (see UpdateNotificationPreferenceRequest — the deliberate opposite of UpdateAppearanceRequest's
    // all-`sometimes` axes, because §23's unit is a row rather than a field). An Inertia visit rather than
    // a sidecar write, unlike /notifications above: this one has a rendered page to redirect back to, and
    // a validation failure has somewhere to put its errors.
    Route::patch('/settings/notifications', [PreferencesController::class, 'updateNotifications'])
        ->name('settings.notifications.update');
    // Tenant-level (org-wide) settings — Owner/Admin only (H10 draft-expiry window). Distinct from the
    // per-user appearance write above; gated on the Spatie permission, not just `auth`.
    Route::patch('/settings/drafts', [TenantSettingsController::class, 'updateDrafts'])
        ->middleware('can:tenant.settings.manage')->name('settings.drafts.update');

    // App Settings (I5, PRD Feature #10) — the same org-wide posture and the same gate as /settings/drafts
    // above. Deliberately NOT `feature:`-gated: these are not paid capabilities, they are how a tenant
    // configures the product it already has, and the Modules write in particular must stay reachable on
    // every plan (it is how a tenant switches a capability back ON after switching it off).
    Route::patch('/settings/access', [TenantSettingsController::class, 'updateAccess'])
        ->middleware('can:tenant.settings.manage')->name('settings.access.update');
    Route::patch('/settings/maintenance', [TenantSettingsController::class, 'updateMaintenance'])
        ->middleware('can:tenant.settings.manage')->name('settings.maintenance.update');
    Route::patch('/settings/modules', [TenantSettingsController::class, 'updateModules'])
        ->middleware('can:tenant.settings.manage')->name('settings.modules.update');

    // Tenant branding (H23a2, ADR-0014). Rendered inside /settings; no page of its own.
    //
    // ⚠️ THE GATING ASYMMETRY BELOW IS DELIBERATE AND MUST NOT BE "TIDIED UP" INTO CONSISTENCY.
    // The two WRITES carry `feature:branding` (Starter+, ADR-0008 §D7). The two REMOVALS do not.
    // This is ADR-0012 §D9 applied: a tenant that brands on Starter and downgrades to Free keeps its
    // stored ramp, stops rendering branded, and must retain a path to delete what a paid tier let them
    // create. Gate the removals and that tenant is stranded with a brand they can neither use nor
    // remove. BrandingRoutesTest asserts both directions, precisely because making all four match looks
    // like a cleanup.
    Route::patch('/settings/branding', [BrandingController::class, 'update'])
        ->middleware(['can:tenant.settings.manage', 'feature:branding'])->name('settings.branding.update');
    Route::delete('/settings/branding', [BrandingController::class, 'destroy'])
        ->middleware('can:tenant.settings.manage')->name('settings.branding.destroy');
    Route::post('/settings/branding/logo', [BrandingController::class, 'storeLogo'])
        ->middleware(['can:tenant.settings.manage', 'feature:branding'])->name('settings.branding.logo.store');
    Route::delete('/settings/branding/logo', [BrandingController::class, 'destroyLogo'])
        ->middleware('can:tenant.settings.manage')->name('settings.branding.logo.destroy');

    // Member administration (Owner/Admin) — authorization is the Spatie permission on each route
    // (B2b). Owner is never invitable; it changes hands only via the ownership-transfer route (§5, §7).
    // The roster page is gated on the same manage ability (the permission catalog is closed — there is no
    // separate members.view — so viewing the roster is an Owner/Admin management surface).
    //
    // ⚠️ THE THREE MUTATIONS CARRY `step-up`, THE READ DOES NOT (I8a, PRD Feature #14). Removing someone,
    // changing what they may do, and handing over the workspace are the tenant-side high-blast-radius
    // actions the PRD names; each requires a password confirmation from the last 15 minutes rather than
    // merely a live session. `members.index` is a read and is deliberately left alone — gating it would
    // put a password prompt in front of simply looking at the roster, which trains people to type their
    // password on reflex and devalues the prompt on the three routes where it means something.
    Route::get('/members', [MemberController::class, 'index'])
        ->middleware('can:tenant.members.invite')->name('members.index');
    Route::post('/members/invitations', [MemberController::class, 'invite'])
        ->middleware('can:tenant.members.invite')->name('members.invite');
    // `tenant.roles.assign` has been seeded to Owner/Admin and documented in RBAC §5 since Phase 0 with
    // ZERO code behind it — the same dormant-key situation I7a found on `feedback.view`. I8a consumes it
    // rather than minting a `tenant.members.role`: the catalog is closed by design, and a second key for
    // the same capability is how a matrix comes to disagree with itself.
    Route::patch('/members/{user}/role', [MemberController::class, 'changeRole'])
        ->middleware(['can:tenant.roles.assign', 'step-up'])->name('members.role');
    Route::delete('/members/{user}', [MemberController::class, 'remove'])
        ->middleware(['can:tenant.members.remove', 'step-up'])->name('members.remove');
    Route::post('/members/ownership', [MemberController::class, 'transferOwnership'])
        ->middleware(['can:tenant.ownership.transfer', 'step-up'])->name('members.ownership');

    // In-app feedback (Feature #11). Every role may SUBMIT (can:feedback.submit); Owner/Admin may VIEW
    // the workspace's own reports (can:feedback.view — seeded since Phase 0, first consumed in I7a). The
    // status lifecycle is deliberately absent here: it belongs to the platform support console.
    Route::get('/feedback', [FeedbackController::class, 'index'])
        ->middleware('can:feedback.view')->name('feedback.index');
    Route::get('/feedback/{feedbackReport}/screenshot', [FeedbackController::class, 'screenshot'])
        ->middleware('can:feedback.view')->name('feedback.screenshot');
    Route::post('/feedback', [FeedbackController::class, 'store'])
        ->middleware('can:feedback.submit')->name('feedback.store');

    // Scoping hierarchy (Increment G10b2) — the UI over the G10b1 backend, and the surface that makes the
    // Reviewer role assignable. Gates mirror ScopeNodePolicy (every method = `scopes.manage`) and
    // ResourceGrantPolicy (`forms.collaborators.manage`). Those two permissions travel together under today's
    // 5-role matrix but are DISTINCT catalog entries, so the grant routes carry their own gate rather than
    // riding the hierarchy one — the same separation `routes/api.php` relies on to make one coarse ability safe.
    //
    // No `ability:` middleware here, unlike the /api/v1 twins: that is Sanctum token-scope middleware and a
    // session request carries no access token. The policy gate IS the authorization on this surface.
    //
    // /scopes/grants would collide with the {scopeNode} pattern, so grants live at their own top-level path
    // (mirroring api.v1.resource-grants.*). Static `impact`/`grants`/`move` segments are declared before the
    // bare PATCH/DELETE {scopeNode} routes.
    Route::get('/scopes', [ScopeNodeController::class, 'index'])
        ->middleware('can:viewAny,'.ScopeNode::class)->name('scopes.index');
    Route::post('/scopes', [ScopeNodeController::class, 'store'])
        ->middleware('can:create,'.ScopeNode::class)->name('scopes.store');
    // The two read-only JSON sidecars (consumed by builderClient, not Inertia): the blast-radius preview the
    // grant modal blocks its confirm on, and the selected node's grant list. Reads only — every mutation on
    // this page is an Inertia visit, because a domain exception on a web route redirects rather than
    // returning JSON (see ScopeNodeController's class docblock).
    Route::get('/scopes/{scopeNode}/impact', [ScopeNodeController::class, 'impact'])
        ->middleware('can:view,scopeNode')->name('scopes.impact');
    Route::get('/scopes/{scopeNode}/grants', [ScopeNodeController::class, 'grants'])
        ->middleware('can:view,scopeNode')->name('scopes.grants');
    Route::post('/scopes/{scopeNode}/move', [ScopeNodeController::class, 'move'])
        ->middleware('can:update,scopeNode')->name('scopes.move');
    Route::patch('/scopes/{scopeNode}', [ScopeNodeController::class, 'update'])
        ->middleware('can:update,scopeNode')->name('scopes.update');
    Route::delete('/scopes/{scopeNode}', [ScopeNodeController::class, 'destroy'])
        ->middleware('can:delete,scopeNode')->name('scopes.destroy');

    // Grants on a scope node. `create` is the base gate; the per-grant escalation rules (no self-grant, no
    // granting wider than your own capacity) are ResourceGrantPolicy::grantCapacity, authorized in the
    // controller because they depend on the capacity and recipient. Revoke gates on `delete`, not
    // grantCapacity — de-escalation is always safe, including revoking a grant you did not issue.
    Route::post('/resource-grants', [ResourceGrantController::class, 'store'])
        ->middleware('can:create,'.ResourceGrant::class)->name('resource-grants.store');
    Route::delete('/resource-grants/{resourceGrant}', [ResourceGrantController::class, 'destroy'])
        ->middleware('can:delete,resourceGrant')->name('resource-grants.destroy');

    // Forms — the durable form record + draft/publish lifecycle (Increment D3). Authorization is the
    // FormPolicy .any/.own composition, resolved by the `can:<ability>,<model>` middleware. The
    // interactive section/field builder is Increment D4; D3 delivers list + metadata + publish/restore.
    Route::get('/forms', [FormController::class, 'index'])
        ->middleware('can:viewAny,'.Form::class)->name('forms.index');
    Route::post('/forms', [FormController::class, 'store'])
        ->middleware('can:create,'.Form::class)->name('forms.store');

    // Form templates (Increment G9a) — the onboarding gallery + instantiate. Registered before the
    // /forms/{form} patterns so the static `templates` segment is never captured as a {form} binding.
    // Both gate on can:create,Form (the gallery exists to create a form from a template); instantiate
    // clones the template's schema_blueprint into a brand-new form's draft (never a live reference).
    Route::get('/forms/templates', [FormTemplateController::class, 'index'])
        ->middleware(['can:create,'.Form::class, 'feature:form_templates'])->name('forms.templates.index');
    Route::post('/forms/templates/{template}/instantiate', [FormTemplateController::class, 'instantiate'])
        ->middleware(['can:create,'.Form::class, 'feature:form_templates'])->name('forms.templates.instantiate');

    Route::patch('/forms/{form}', [FormController::class, 'update'])
        ->middleware('can:update,form')->name('forms.update');
    Route::post('/forms/{form}/archive', [FormController::class, 'archive'])
        ->middleware('can:delete,form')->name('forms.archive');
    Route::post('/forms/{form}/publish', [FormPublishController::class, 'store'])
        ->middleware('can:publish,form')->name('forms.publish');
    Route::post('/forms/{form}/versions/{version}/restore', [FormPublishController::class, 'restore'])
        ->middleware('can:update,form')->name('forms.restore');

    // Save a form as a tenant-owned private template (Increment G9a) — snapshots the current draft's live
    // rows into a new form_templates row. A read/derive of the form → gated can:view,form (mirrors export).
    Route::post('/forms/{form}/save-as-template', [FormTemplateController::class, 'storeFromForm'])
        ->middleware(['can:view,form', 'feature:form_templates'])->name('forms.templates.store-from-form');

    // XLSForm export (Increment G7a) — download any version (draft or published) as an .xlsx workbook, the
    // browser-facing twin of the doc-pinned /api/v1 endpoint. {version} is scope-bound to {form}; gated
    // can:view,form (read access), mirroring the submissions-export download.
    Route::get('/forms/{form}/versions/{version}/xlsform', [FormXlsformController::class, 'export'])
        ->scopeBindings()
        ->middleware(['can:view,form', 'feature:xlsform_export'])->name('forms.xlsform.export');

    // XLSForm import (Increment G7b) — destructively replace the form's current DRAFT with an uploaded .xlsx
    // (docs/xlsform-interop-spec.md §5, mirroring "restore version"). A write → gated can:update,form (not the
    // export's read gate); parse failures reject the file UPFRONT, before the draft is touched.
    Route::post('/forms/{form}/draft/xlsform-import', [FormXlsformController::class, 'import'])
        ->middleware(['can:update,form', 'feature:xlsform_export'])->name('forms.xlsform.import');

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

    // Question library (Increment G9b) — insert a reusable question into the draft, save a draft field back to
    // the library, and refetch the picker list. Draft writes → gated can:update,form like the other builder
    // mutations; insert delegates to SchemaBlueprintMaterializer::materializeField, save to FieldLibrary::fromField.
    // `from-library` is a literal segment (no {field}) so it never collides with the fields/{field} routes above.
    Route::post('/forms/{form}/fields/from-library', [FormBuilderController::class, 'storeFieldFromLibrary'])
        ->middleware(['can:update,form', 'feature:field_library'])->name('forms.fields.from-library');
    Route::post('/forms/{form}/fields/{field}/save-to-library', [FormBuilderController::class, 'saveFieldToLibrary'])
        ->middleware(['can:update,form', 'feature:field_library'])->name('forms.fields.save-to-library');
    Route::get('/forms/{form}/library-items', [FormBuilderController::class, 'libraryItems'])
        ->middleware(['can:update,form', 'feature:field_library'])->name('forms.library-items');

    // The builder's relevance-graph sidecar (Increment H21d1, Doc #27 §8) — the Logic view's read-only JSON
    // feed of the DRAFT's branching notices, consumed by builderClient like the ScopeNode impact/grants
    // reads above. A read that WRITES NOTHING, so no 409 and no `respond()` mapping; gated `can:update,form`
    // like every other builder route, not `can:view` — this surface exists only inside the editor.
    Route::get('/forms/{form}/graph', [FormBuilderController::class, 'graph'])
        ->middleware('can:update,form')->name('forms.graph');

    // Assign a form to the scoping hierarchy (Increment G10b2). Deliberately NOT part of PATCH /forms/{form}:
    // writing scope_node_id confers capacity on the form — and via SubmissionPolicy on its whole submission
    // history — to every holder of a grant on that node and on any includes_descendants ancestor. `can:update,form`
    // alone is held by a plain Form Editor on any form they created, so the hierarchy-authoring permission is
    // stacked on top. Both gates must pass; the write itself is FormService::assignScope (never mass-assignment).
    Route::patch('/forms/{form}/scope', [FormScopeController::class, 'update'])
        ->middleware(['can:update,form', 'can:viewAny,'.ScopeNode::class])->name('forms.scope');

    // Per-form save-and-resume opt-in (Increment H10, UX §5.2). Like the scope route, its own endpoint with a
    // guarded FormService write. `feature:save_and_resume` stacks on `can:update,form` so a form owner can only
    // enable a feature the tenant plan includes; the guest runtime + draft channel both consult the flag.
    Route::patch('/forms/{form}/save-resume', [FormSaveResumeController::class, 'update'])
        ->middleware(['can:update,form', 'feature:save_and_resume'])->name('forms.save-resume');

    // Scheduled forms (Increment H12a) — set/clear a form's open/close window + response cap. Its own route +
    // guarded FormService::setSchedule write. Ungated (all tiers): scheduled forms carry no plan feature, so
    // only can:update,form gates it (schedule config is an editor's job, like save-resume/scope above).
    Route::patch('/forms/{form}/schedule', [FormScheduleController::class, 'update'])
        ->middleware('can:update,form')->name('forms.schedule');

    // The confirmation message (Increment H6a, Doc #26 §6.2) — the thank-you copy the guest runtime shows
    // after a submit, and the first author-editable text that may carry `${key}` piping holes. Same shape
    // as the schedule route above: its own endpoint, a guarded FormService::setConfirmationMessage write,
    // ungated by plan (confirmation copy carries no tier feature). The request rule checks grammar; the
    // publish gate resolves the references.
    Route::patch('/forms/{form}/confirmation', [FormConfirmationMessageController::class, 'update'])
        ->middleware('can:update,form')->name('forms.confirmation');

    // The share surface (Increment I1, PRD Feature #3) — the public link name + the guest-access toggle, the
    // two columns the guest runtime resolves on and that until now had no writer outside the XLSForm importer
    // and the e2e seeder. Same shape as the four routes above: its own endpoint, a guarded
    // FormService::setShareSettings write, never mass-assignment (both columns ARE fillable, and together they
    // are the difference between a private draft and an open collection endpoint).
    //
    // Ungated by plan, deliberately: no entitlement in the catalog covers guest forms, and inventing one here
    // would be a pricing decision rather than the enforcement of one. What IS tiered — reaching the form on a
    // custom domain — is gated on the domains surface, where that was decided.
    //
    // The QR is a GET on the same gate rather than a data URI in the page props, so "download the QR" is a
    // plain browser navigation (the XLSForm-export precedent) instead of a canvas round-trip.
    Route::patch('/forms/{form}/share', [FormShareController::class, 'update'])
        ->middleware('can:update,form')->name('forms.share');
    Route::get('/forms/{form}/share/qr.svg', FormShareQrController::class)
        ->middleware('can:update,form')->name('forms.share.qr');

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
    // Queued single-submission PDF (Increment H17). POST, not GET: it has side effects (an audit row, a
    // metered export, a queued job). Gates on `can:view` rather than the per-form `can:export` used
    // above — the file contains exactly the one submission the user is already permitted to read on
    // this page, so requiring the form-wide export capability would be a stricter gate than the data
    // warrants. Returns a redirect + toast; the artifact arrives by email and on the detail page.
    Route::post('/submissions/{submission}/pdf', [SubmissionInboxController::class, 'generatePdf'])
        ->middleware('can:view,submission')->name('submissions.pdf');

    // Attachments (Increment G6) — the shared polymorphic media write path. `store` stages an uploaded file
    // against the form's published version (SubmissionPipeline re-points it to the submission at persist),
    // gated by the same create policy as manual encoding. `show` streams a stored file back for the inbox/
    // encode preview, gated on AttachmentPolicy::view and withheld until its scan status is servable. RLS
    // scopes both to the tenant (a cross-tenant {attachment} id 404s at binding).
    Route::post('/forms/{form}/attachments', [AttachmentController::class, 'store'])
        ->middleware('can:create,'.Submission::class.',form')->name('forms.attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])
        ->middleware('can:view,attachment')->name('attachments.show');

    // Webhook management + delivery-log UI (Increment H14) — the session-authed Inertia surface over the
    // H13a/H13b engine, delegating to the SAME WebhookEndpointService as the /api/v1 twins (routes/api.php).
    // Gates mirror the API MINUS `ability:` (Sanctum token-scope only; a session carries no token, so the
    // WebhookEndpointPolicy `can:` gate IS the authorization here). `feature:webhooks` stacks the Starter+ plan
    // gate — the nav item is hidden for tiers without it, and a direct visit bounces with an upgrade toast.
    // Static action segments (/test, /rotate-secret, /deliveries) are declared before the bare
    // GET/PATCH/DELETE {webhookEndpoint} routes so they are never captured as a binding. Every mutation is an
    // Inertia visit → redirect + flash; there is no JSON sidecar (test.ping flashes its synchronous result).
    Route::get('/webhooks', [WebhookController::class, 'index'])
        ->middleware(['can:viewAny,'.WebhookEndpoint::class, 'feature:webhooks'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])
        ->middleware(['can:create,'.WebhookEndpoint::class, 'feature:webhooks'])->name('webhooks.store');
    Route::post('/webhooks/{webhookEndpoint}/test', [WebhookController::class, 'test'])
        ->middleware(['can:update,webhookEndpoint', 'feature:webhooks'])->name('webhooks.test');
    Route::post('/webhooks/{webhookEndpoint}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->middleware(['can:update,webhookEndpoint', 'feature:webhooks'])->name('webhooks.rotate-secret');
    Route::post('/webhooks/{webhookEndpoint}/deliveries/{webhookDelivery}/redeliver', [WebhookController::class, 'redeliver'])
        ->middleware(['can:update,webhookEndpoint', 'feature:webhooks'])->name('webhooks.deliveries.redeliver');
    Route::get('/webhooks/{webhookEndpoint}', [WebhookController::class, 'show'])
        ->middleware(['can:view,webhookEndpoint', 'feature:webhooks'])->name('webhooks.show');
    Route::patch('/webhooks/{webhookEndpoint}', [WebhookController::class, 'update'])
        ->middleware(['can:update,webhookEndpoint', 'feature:webhooks'])->name('webhooks.update');
    Route::delete('/webhooks/{webhookEndpoint}', [WebhookController::class, 'destroy'])
        ->middleware(['can:delete,webhookEndpoint', 'feature:webhooks'])->name('webhooks.destroy');

    // Custom-domain admin UI (Increment H22b / ADR-0012) — the session-authed Inertia surface over the H22a
    // lifecycle, delegating to the SAME CustomDomainService as the /api/v1 twins (routes/api.php). Gates
    // mirror those twins MINUS `ability:manage:domains`, which is Sanctum token-scope middleware a session
    // request cannot carry; `can:tenant.settings.manage` IS the authorization here (the H14 convention). It
    // is a bare permission rather than a policy because there is no per-instance authorization question —
    // and the resource deliberately has no policy at all (see the API block for why).
    //
    // ⚠️ index AND destroy CARRY NO `feature:` GATE, AND THAT IS NOT AN OVERSIGHT. ADR-0012 §D9: a tenant
    // downgraded off Business keeps a LIVE, resolving hostname, so gating the read would strand it with a
    // domain it can see nowhere and remove nowhere. The nav item still requires the feature, so an
    // unentitled tenant does not see a destination — it reaches this page from the Settings link that
    // appears only once it actually holds a domain.
    //
    // {domain} IS NOT A BOUND MODEL — `domains` is RLS-exempt, so implicit binding would resolve any
    // tenant's row with no database backstop; the controller filters by tenant_id explicitly. Static action
    // segments precede the bare DELETE {domain} route (the H14 rule).
    //
    // THERE IS DELIBERATELY NO ACTIVATE ROUTE. /primary is not one: it refuses any domain that is not
    // already live, so it chooses among hosts an operator activated and can never produce one.
    Route::get('/domains', [DomainController::class, 'index'])
        ->middleware('can:tenant.settings.manage')->name('domains.index');
    Route::post('/domains', [DomainController::class, 'store'])
        ->middleware(['can:tenant.settings.manage', 'feature:custom_domain'])->name('domains.store');
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])
        ->where('domain', '[A-Za-z0-9.-]+')
        ->middleware(['can:tenant.settings.manage', 'feature:custom_domain'])->name('domains.verify');
    Route::post('/domains/{domain}/primary', [DomainController::class, 'primary'])
        ->where('domain', '[A-Za-z0-9.-]+')
        ->middleware(['can:tenant.settings.manage', 'feature:custom_domain'])->name('domains.primary');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])
        ->where('domain', '[A-Za-z0-9.-]+')
        ->middleware('can:tenant.settings.manage')->name('domains.destroy');

    // Native-connector management UI (Increment H15b) — the session-authed Inertia surface over the H15a
    // framework, delegating to the SAME ConnectionService / ConnectionSubscriptionService as the /api/v1 twins
    // (routes/api.php). Gates mirror the API MINUS `ability:` (Sanctum token-scope only; a session carries no
    // token, so the policy `can:` gate IS the authorization here) plus the `feature:native_connectors` Starter+
    // plan gate — the nav item is hidden for tiers without it, and a direct visit bounces with an upgrade toast.
    //
    // SHALLOW NESTING: only `store` binds a {connection}, because a rule is created ON a grant but thereafter
    // has its own page. The rest are flat and gated by ConnectionSubscriptionPolicy — a nested {connection}
    // binding would 404 after a disconnect, which soft-deletes the grant while KEEPING its rules (paused).
    // Nothing here can shadow /integrations/{provider}/connect below: that pattern's third segment must be the
    // literal string `connect`. Static `/test` precedes the bare {connectionSubscription} verbs (the H14 rule).
    Route::get('/integrations', [ConnectionController::class, 'index'])
        ->middleware(['can:viewAny,'.Connection::class, 'feature:native_connectors'])->name('integrations.index');
    Route::get('/integrations/rules/{connectionSubscription}', [ConnectionRuleController::class, 'show'])
        ->middleware(['can:view,connectionSubscription', 'feature:native_connectors'])->name('integrations.rules.show');
    Route::post('/integrations/rules/{connectionSubscription}/test', [ConnectionRuleController::class, 'test'])
        ->middleware(['can:update,connectionSubscription', 'feature:native_connectors'])->name('integrations.rules.test');
    Route::patch('/integrations/rules/{connectionSubscription}', [ConnectionRuleController::class, 'update'])
        ->middleware(['can:update,connectionSubscription', 'feature:native_connectors'])->name('integrations.rules.update');
    Route::delete('/integrations/rules/{connectionSubscription}', [ConnectionRuleController::class, 'destroy'])
        ->middleware(['can:delete,connectionSubscription', 'feature:native_connectors'])->name('integrations.rules.destroy');
    Route::post('/integrations/connections/{connection}/rules', [ConnectionRuleController::class, 'store'])
        ->middleware(['can:update,connection', 'feature:native_connectors'])->name('integrations.rules.store');
    // The one read-only JSON sidecar on this surface (the scopes.impact/scopes.grants precedent) — the channel
    // picker must load without navigating. Every MUTATION above is an Inertia visit, because a domain exception
    // on a web route redirects rather than returning JSON.
    Route::get('/integrations/connections/{connection}/channels', [ConnectionController::class, 'channels'])
        ->middleware(['can:view,connection', 'feature:native_connectors'])->name('integrations.connections.channels');
    Route::delete('/integrations/connections/{connection}', [ConnectionController::class, 'destroy'])
        ->middleware(['can:delete,connection', 'feature:native_connectors'])->name('integrations.connections.destroy');

    // Native-connector OAuth start (H15a / ADR-0009). The ONLY half of the flow with a session: it mints the
    // signed `state` that carries tenant + user identity to the central-domain callback (routes/connectors.php),
    // which has no session to read them from, and redirects away to the provider's consent screen.
    //
    // Gates mirror the API MINUS `ability:` — a session carries no token, so the ConnectionPolicy `can:` gate
    // IS the authorization here (the H14 convention) — plus the `feature:native_connectors` Starter+ plan gate.
    // No page is rendered: H15a is backend+api, and H15b builds the Integrations UI that links here.
    Route::get('/integrations/{provider}/connect', [ConnectorAuthController::class, 'redirect'])
        ->middleware(['can:create,'.Connection::class, 'feature:native_connectors'])
        ->name('integrations.connect');

    // Cross-form analytics (ADR-0011, Increment H24b2) — the Business-gated page over H24a's substrate.
    // Gates mirror the /api/v1 twins (routes/api.php) key for key MINUS `ability:read:analytics`, which is
    // Sanctum token-scope middleware a session request cannot carry; the policy `can:` gate IS the
    // authorization here. `viewAny` is doing real work on the read routes: SavedReportViewPolicy's docblock
    // records that it doubles as the gate for the report/question surfaces, which have no model of their own.
    //
    // §D9: the surface is HIDDEN for a tenant without `advanced_analytics`, never locked-with-upsell —
    // Business is seeded is_active:false, so an upgrade CTA would point at a plan nobody can buy. A direct
    // visit still bounces off `feature:` with a toast.
    //
    // Filters are QUERY PARAMS on the index route, re-fetched as Inertia partial reloads — there is no JSON
    // sidecar here. The question aggregate would be the obvious candidate, but it can raise a domain
    // exception, and on a web route that returns a 302 with a flash even to an XHR (the integrationsClient
    // trap); it is also a function of the whole declaration, which already lives in the URL.
    //
    // Static segments precede the binding (the H14 rule): /analytics/export and /analytics/views are declared
    // before /analytics/views/{savedReportView}, and there is no /analytics/{binding} route at all.
    Route::get('/analytics', [AnalyticsController::class, 'index'])
        ->middleware(['can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])->name('analytics.index');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])
        ->middleware(['can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])->name('analytics.export');
    Route::post('/analytics/views', [AnalyticsViewController::class, 'store'])
        ->middleware(['can:create,'.SavedReportView::class, 'feature:advanced_analytics'])->name('analytics.views.store');
    // update/delete gate on the BOUND INSTANCE, where the policy's owner check runs: saved_report_views is
    // `strict` RLS, which scopes to the tenant only, so without it any co-tenant could edit a colleague's view.
    Route::patch('/analytics/views/{savedReportView}', [AnalyticsViewController::class, 'update'])
        ->middleware(['can:update,savedReportView', 'feature:advanced_analytics'])->name('analytics.views.update');
    Route::delete('/analytics/views/{savedReportView}', [AnalyticsViewController::class, 'destroy'])
        ->middleware(['can:delete,savedReportView', 'feature:advanced_analytics'])->name('analytics.views.destroy');

    /*
    | The audit log (Increment I2, PRD Feature #12) — the Owner/Admin compliance ledger, the first surface
    | that lets a human READ what H4 has been writing since July.
    |
    | `can:viewAny,Audit` IS the authorization: AuditPolicy resolves exactly the `audit_log.view`
    | permission, seeded to Owner and Admin only and explicitly withheld from Viewer. No `ability:` — that
    | is Sanctum token-scope middleware and a session carries no token (the H14 convention).
    |
    | NO `feature:` GATE, AND THE ABSENCE IS DELIBERATE. `PlanCatalog` defines no audit key on any tier,
    | because accountability is a baseline obligation rather than an upsell — so unlike Analytics, Webhooks,
    | Integrations and Domains, this destination turns on a permission alone. Adding a feature key here
    | would be MAKING a pricing decision rather than enforcing one (the I1 forms.share precedent).
    |
    | Static segment before any binding (the H14 rule): /audit-log/export is declared here, and there is
    | deliberately NO /audit-log/{audit} route. An audit row has no detail page — the before/after diff is
    | rendered from props the list already carries, so the row IS the detail — and a bare {audit} pattern
    | would be the first thing able to shadow /export.
    */
    Route::get('/audit-log', [AuditLogController::class, 'index'])
        ->middleware('can:viewAny,'.Audit::class)->name('audit-log.index');
    Route::get('/audit-log/export', [AuditLogController::class, 'export'])
        ->middleware('can:viewAny,'.Audit::class)->name('audit-log.export');
});

/*
| The 2FA enrollment interstitial (Increment I8a, PRD Feature #14) — one route, and the ONLY reason it is
| a group of its own is that it must sit OUTSIDE EnforceTenantTwoFactor.
|
| ⚠️ A GATE THAT REDIRECTS EVERYWHERE REDIRECTS TO ITSELF. This is the same carve-out routes/admin.php has
| given `admin.mfa.setup` since B2c, drawn the same way — STRUCTURALLY, by living outside the guarded
| group, rather than as a path allow-list inside EnforceTenantTwoFactor. The difference matters: an
| allow-list would also have to name `/settings` (the personal 2FA panel lives there), and every future
| `/settings/*` route would then inherit an exemption nobody decided to grant. One route out, visible here.
|
| Everything else in the pipeline is identical to the authenticated group above — this page needs the same
| tenant context, the same session, and the same security headers. Only the enforcement gate is absent.
| Sign-out is reachable from it because POST /logout is a Fortify route in its own group; that is the
| second door "enroll or leave" requires, and it must not be tidied inside this gate.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    'auth',
    AppSecurityHeaders::class,
])->group(function (): void {
    Route::get('/two-factor/required', TwoFactorRequiredController::class)->name('two-factor.required');
});

/*
| Invitation accept/decline — the invitee side (B2b). Same subdomain tenant-context pipeline as above
| but WITHOUT `auth`: the invitee is not a member yet, so requiring auth would be circular. Tenant
| context is still established, which is exactly what makes the strict-RLS invite row visible (only
| within its own tenant) and lets accept materialize the reserved role. Styled pages land in Increment C.
|
| H23a4 adds the brand logo to this group for the same structural reason and a different audience: the
| caller is an EMAIL CLIENT, which carries no session and often fetches through a proxy days after the
| message was sent. It stays on the APP host (this group identifies by subdomain) because ADR-0009 §D2 and
| ADR-0012 scope a custom domain to the guest runtime only — so the logo URL in every branded email is
| composed by TenantUrl's app arm, and this is the group that serves it. Deliberately NOT signed: an
| expiry on an image inside an inbox is a broken image on a timer. See BrandingLogoController.
*/
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    // AppSecurityHeaders (I1): the invitation pages accept a role-granting POST from a link in an email —
    // exactly the shape of act that must not be framed. The branding logo shares this group and is served to
    // email clients, which the header does not affect (frame-ancestors governs framing, not <img> fetching).
    AppSecurityHeaders::class,
])->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('/invitations/{token}', [InvitationController::class, 'decline'])->name('invitations.decline');

    // No `feature:branding` middleware: the gate is inside the controller, via
    // TenantBrandingService::isActive(), because an unentitled tenant must answer 404 (an image that is
    // not there) and not the middleware's 403 (an image that exists and is being withheld).
    Route::get('/branding/logo', BrandingLogoController::class)->name('branding.logo');
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
// Increment G8a — re-serve the Vite-built guest PWA service worker from the root so its scope can cover
// /f/ (a /build/ static can't carry Service-Worker-Allowed under artisan serve). Deliberately outside the
// tenancy group: it streams a tenant-agnostic build artifact and needs no tenant/session context.
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.sw');

// H22a — THE ONLY GROUP A TENANT'S OWN CUSTOM DOMAIN MAY SERVE. ADR-0009 §D2 already fixed the scope:
// "Business-tier `custom_domain` covers public forms, not the admin app", with moving the app itself onto
// custom domains recorded there as a future revisit trigger. So identification here is
// InitializeTenancyByPublicHost (subdomain arm for platform hosts, full-host lookup for a verified custom
// domain) while every other group below and in routes/api.php keeps InitializeTenancyBySubdomain — a custom
// host on those throws NotASubdomainException, which bootstrap/app.php renders as a redirect to the central
// app. That is what keeps the authenticated app a single origin, and it is why this increment has no
// cross-host session, CSRF, OAuth-return or bearer-token surface to defend.
//
// A custom domain reaches this group only once it is `live` (verified by DNS TXT *and* activated by an
// operator after a certificate was installed by hand) — enforced by the ResolvableDomainScope on
// App\Models\Domain, so stancl's own resolver query cannot see any other row.
Route::middleware([
    'web',
    InitializeTenancyByPublicHost::class,
    PreventAccessFromCentralDomains::class,
    EstablishTenantDatabaseContext::class,
    // The guest SPA shell hosts the G5b2 geo control's Leaflet map → allow the OSM tile origin (ADR-0006 D3).
    PublicRuntimeSecurityHeaders::class,
    // Tenant maintenance mode (I5 / PRD Feature #10). LISTED AFTER the identification middleware above, and
    // that position is load-bearing: it reads the flag off the tenant they have already resolved and bound,
    // which is the whole reason `maintenance_mode` is a `tenants` column and not a `settings` row. Sorted
    // ahead of them it would find no tenant, pass everything through, and leave the feature dead with a
    // green build — TenancyMiddlewarePriorityTest asserts the resolved order.
    EnforceTenantMaintenance::class,
])->group(function (): void {
    Route::get('/f/{slug}', [GuestFormController::class, 'mint'])
        ->middleware('throttle:guest-mint')->name('guest.form.mint');

    // Increment H9b — the save-and-resume entry. Opens the guest SPA shell from an emailed resume link
    // (a static "resume" segment, so it never collides with a `public_slug` at `/f/{slug}`). The token is
    // verified in the controller (404 on bad/expired); the SPA then restores the draft (restore itself is H10).
    Route::get('/f/resume/{resumeToken}', [GuestFormController::class, 'resume'])
        ->middleware('throttle:guest-mint')->name('guest.form.resume');

    // Increment G8a — per-form web manifest linked from the guest shell (installability). Same slug
    // resolution + 404 gates as the mint route; no share token needed.
    Route::get('/f/{slug}/manifest.webmanifest', PwaManifestController::class)->name('guest.form.manifest');
});
