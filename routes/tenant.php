<?php

declare(strict_types=1);

use App\Http\Controllers\Public\GuestFormController;
use App\Http\Controllers\Public\PwaManifestController;
use App\Http\Controllers\Public\ServiceWorkerController;
use App\Http\Controllers\Tenant\AttachmentController;
use App\Http\Controllers\Tenant\ConnectionController;
use App\Http\Controllers\Tenant\ConnectionRuleController;
use App\Http\Controllers\Tenant\ConnectorAuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\FeedbackController;
use App\Http\Controllers\Tenant\FormBuilderController;
use App\Http\Controllers\Tenant\FormController;
use App\Http\Controllers\Tenant\FormPublishController;
use App\Http\Controllers\Tenant\FormSaveResumeController;
use App\Http\Controllers\Tenant\FormScheduleController;
use App\Http\Controllers\Tenant\FormScopeController;
use App\Http\Controllers\Tenant\FormTemplateController;
use App\Http\Controllers\Tenant\FormXlsformController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\MemberController;
use App\Http\Controllers\Tenant\PreferencesController;
use App\Http\Controllers\Tenant\ResourceGrantController;
use App\Http\Controllers\Tenant\ScopeNodeController;
use App\Http\Controllers\Tenant\SubmissionController;
use App\Http\Controllers\Tenant\SubmissionInboxController;
use App\Http\Controllers\Tenant\SubmissionReviewController;
use App\Http\Controllers\Tenant\TenantSettingsController;
use App\Http\Controllers\Tenant\WebhookController;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\PublicRuntimeSecurityHeaders;
use App\Models\Connection;
use App\Models\Form;
use App\Models\ResourceGrant;
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
    // The authenticated landing page (H11) — real, visibility-scoped KPI counts from DashboardMetricsService.
    // No `can:` gate: every role lands here after login; the per-role scoping is the service's job.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Settings — the current appearance reaches the page via the shared `ui.theme` prop (no controller
    // needed for the read). The appearance write persists the four personalization axes to
    // user_ui_preferences; it lives here (not central) so app.current_user_id is set and the
    // belongs-to-user RLS write succeeds. Partial by design — see UpdateAppearanceRequest.
    Route::get('/settings', [PreferencesController::class, 'show'])->name('settings');
    Route::patch('/settings/appearance', [PreferencesController::class, 'updateAppearance'])
        ->name('settings.appearance.update');
    // Tenant-level (org-wide) settings — Owner/Admin only (H10 draft-expiry window). Distinct from the
    // per-user appearance write above; gated on the Spatie permission, not just `auth`.
    Route::patch('/settings/drafts', [TenantSettingsController::class, 'updateDrafts'])
        ->middleware('can:tenant.settings.manage')->name('settings.drafts.update');

    // Member administration (Owner/Admin) — authorization is the Spatie permission on each route
    // (B2b). Owner is never invitable; it changes hands only via the ownership-transfer route (§5, §7).
    // The roster page is gated on the same manage ability (the permission catalog is closed — there is no
    // separate members.view — so viewing the roster is an Owner/Admin management surface).
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
// Increment G8a — re-serve the Vite-built guest PWA service worker from the root so its scope can cover
// /f/ (a /build/ static can't carry Service-Worker-Allowed under artisan serve). Deliberately outside the
// tenancy group: it streams a tenant-agnostic build artifact and needs no tenant/session context.
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.sw');

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

    // Increment H9b — the save-and-resume entry. Opens the guest SPA shell from an emailed resume link
    // (a static "resume" segment, so it never collides with a `public_slug` at `/f/{slug}`). The token is
    // verified in the controller (404 on bad/expired); the SPA then restores the draft (restore itself is H10).
    Route::get('/f/resume/{resumeToken}', [GuestFormController::class, 'resume'])
        ->middleware('throttle:guest-mint')->name('guest.form.resume');

    // Increment G8a — per-form web manifest linked from the guest shell (installability). Same slug
    // resolution + 404 gates as the mint route; no share token needed.
    Route::get('/f/{slug}/manifest.webmanifest', PwaManifestController::class)->name('guest.form.manifest');
});
