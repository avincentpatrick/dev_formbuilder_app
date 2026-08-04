<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnalyticsQuestionController;
use App\Http\Controllers\Api\V1\AnalyticsReportController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AuditApiController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\ConnectionSubscriptionController;
use App\Http\Controllers\Api\V1\DomainApiController;
use App\Http\Controllers\Api\V1\FieldLibraryApiController;
use App\Http\Controllers\Api\V1\FormApiController;
use App\Http\Controllers\Api\V1\FormTemplateApiController;
use App\Http\Controllers\Api\V1\FormVersionApiController;
use App\Http\Controllers\Api\V1\FormXlsformApiController;
use App\Http\Controllers\Api\V1\ResourceGrantApiController;
use App\Http\Controllers\Api\V1\SavedReportViewController;
use App\Http\Controllers\Api\V1\ScopeNodeApiController;
use App\Http\Controllers\Api\V1\SubmissionPromoteController;
use App\Http\Controllers\Api\V1\SyncManifestController;
use App\Http\Controllers\Api\V1\SyncSubmissionController;
use App\Http\Controllers\Api\V1\TenantApiController;
use App\Http\Controllers\Api\V1\WebhookDeliveryController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Controllers\Public\GuestAttachmentController;
use App\Http\Controllers\Public\GuestDraftController;
use App\Http\Controllers\Public\GuestDraftResumeController;
use App\Http\Controllers\Public\GuestSubmissionController;
use App\Http\Controllers\Public\PublicFormSchemaController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceApiRequestQuota;
use App\Http\Middleware\EstablishGuestDraftContext;
use App\Http\Middleware\EstablishGuestTenantContext;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\MeterApiUsage;
use App\Models\Audit;
use App\Models\Connection;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiAbilities;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| API routes — the versioned REST surface /api/v1 (Increment E)
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php withRouting(then:). Served on the tenant subdomain
| (acme.meridian.test/api/v1/...) — subdomain identification resolves the tenant, and RLS enforces
| isolation, exactly like routes/tenant.php. Two groups with different auth:
|   A. Token issuance — SESSION auth (a logged-in member mints an API key from the app).
|   B. Token-consumed resources — Sanctum bearer-token auth (AuthenticateApiToken) + `ability:` gates,
|      composed with the FormPolicy `can:` gate. See docs/api-specification.md.
| The {error:{code,message,details}} envelope + the `api` rate limit + the priority slot for
| AuthenticateApiToken all live in bootstrap/app.php / AppServiceProvider.
*/

// ── Group A: token issuance (session-authenticated; the mint/list/revoke surface) ─────────────
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        EstablishTenantDatabaseContext::class,
        'auth',
    ])
    ->group(function (): void {
        // A key is minted scoped to the issuer's own RBAC (requested abilities are intersected against
        // the issuer's permissions server-side), so it can never exceed its issuer; any active member may
        // mint one (a non-member's key trims to no abilities and its tokenable is hidden by RLS on use).
        // The plaintext secret is returned exactly once, on create.
        // Minting a key requires api_access (H5c) — a plan without the REST API cannot issue one. List/revoke
        // stay ungated so a downgraded tenant can still see and revoke its now-unusable keys.
        Route::post('auth/tokens', [ApiTokenController::class, 'store'])
            ->middleware('feature:api_access')->name('tokens.store');
        Route::get('auth/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
        // {id} constrained to digits — personal_access_tokens.id is a bigint; a non-numeric id would
        // otherwise reach the query and 500 on a bad cast instead of a clean 404.
        Route::delete('auth/tokens/{id}', [ApiTokenController::class, 'destroy'])
            ->whereNumber('id')->name('tokens.destroy');
    });

// ── Group B: token-consumed resources (Sanctum bearer auth + ability + policy) ────────────────
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware([
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        EstablishTenantDatabaseContext::class,
        AuthenticateApiToken::class,
        // api_access feature-gate (H5c) — gates the WHOLE token-consumed REST API. Immediately after auth
        // (tenant context is set; a 401 for no-token precedes a 402 for no-feature) and before throttle so a
        // no-feature tenant is refused before consuming a burst slot. A grandfathered tenant's override passes.
        'feature:api_access',
        'throttle:api',
        // Monthly api_requests quota (H5c) — 429 when the metered current-period usage has reached the plan
        // quota. After the burst throttle; before MeterApiUsage so a 429'd request is not itself metered. A
        // quota of null (unlimited) or 0 (the Free "no API" sentinel, reached only via a grandfather override)
        // never 429s. See EnforceApiRequestQuota.
        EnforceApiRequestQuota::class,
        // Meters api_requests (H5b) — AFTER auth + burst throttle + the quota gate, so an unauthenticated 401,
        // a rate-limited 429, or an over-quota 429 is not counted toward the tenant's monthly volume.
        MeterApiUsage::class,
        SubstituteBindings::class,
    ])
    ->group(function (): void {
        Route::get('tenant', [TenantApiController::class, 'show'])
            ->middleware('ability:'.ApiAbilities::READ_FORMS)
            ->name('tenant.show');

        Route::get('forms', [FormApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:viewAny,'.Form::class])
            ->name('forms.index');

        Route::get('forms/{form}', [FormApiController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:view,form'])
            ->name('forms.show');

        Route::get('forms/{form}/versions', [FormVersionApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:view,form'])
            ->name('forms.versions.index');

        Route::get('forms/{form}/versions/{version}', [FormVersionApiController::class, 'show'])
            ->scopeBindings()
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:view,form'])
            ->name('forms.versions.show');

        // XLSForm export (Increment G7a / docs/xlsform-interop-spec.md) — stream a version as an .xlsx
        // workbook for the Kobo/ODK migration path. Scope-bound {version}; read ability + can:view,form.
        Route::get('forms/{form}/versions/{version}/xlsform', [FormXlsformApiController::class, 'export'])
            ->scopeBindings()
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:view,form', 'feature:xlsform_export'])
            ->name('forms.versions.xlsform');

        // XLSForm import (Increment G7b / docs/xlsform-interop-spec.md §5) — destructively replace the form's
        // current draft with the uploaded .xlsx. A write → WRITE_FORMS + can:update,form (mirroring publish).
        Route::post('forms/{form}/draft/xlsform-import', [FormXlsformApiController::class, 'import'])
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'can:update,form', 'feature:xlsform_export'])
            ->name('forms.xlsform.import');

        // Publish the form's current draft (docs/form-versioning-schema-migration.md §3.2). The URL names
        // the draft version; the controller rejects a non-draft {version} before delegating to PublishService.
        Route::post('forms/{form}/versions/{version}/publish', [FormVersionApiController::class, 'publish'])
            ->scopeBindings()
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'can:publish,form'])
            ->name('forms.versions.publish');

        // Form templates (Increment G9a / architecture §7.1) — reusable whole-form blueprints. `index` lists
        // the gallery RLS returns (platform + own); `store` saves a version as a tenant-owned private template.
        // Read gates on read:forms; write on write:forms + an in-controller can:view on the source form (the
        // version is a body param, not a bound model). Regenerate openapi.json after adding these.
        Route::get('form-templates', [FormTemplateApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'feature:form_templates'])
            ->name('form-templates.index');
        Route::post('form-templates', [FormTemplateApiController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'feature:form_templates'])
            ->name('form-templates.store');

        // Question library (Increment G9b / architecture §7.1) — reusable single questions. `index` lists the
        // items RLS returns (platform + own, active); `store` authors a tenant-owned reusable question. Read
        // gates on read:forms, write on write:forms (a library item is authoring metadata, not a bound model —
        // no can: gate, mirroring form-templates). Regenerate openapi.json after adding these.
        Route::get('field-library', [FieldLibraryApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'feature:field_library'])
            ->name('field-library.index');
        Route::post('field-library', [FieldLibraryApiController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'feature:field_library'])
            ->name('field-library.store');

        // Scoping hierarchy (Increment G10b / multi-tenancy-rbac-design.md §8) — the tenant's own node tree.
        // Every route pairs `ability:manage:scopes` with a ScopeNodePolicy `can:` gate: the ability scopes the
        // TOKEN, the policy re-checks the acting user's real permissions. `move` is a separate endpoint rather
        // than part of PATCH because it is a locked, subtree-wide operation with its own refusals (cycle,
        // depth cap). Static segments precede the {scopeNode} patterns. Regenerate openapi.json after adding.
        Route::get('scopes', [ScopeNodeApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:viewAny,'.ScopeNode::class])
            ->name('scopes.index');
        Route::post('scopes', [ScopeNodeApiController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:create,'.ScopeNode::class])
            ->name('scopes.store');
        Route::get('scopes/{scopeNode}/impact', [ScopeNodeApiController::class, 'impact'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:view,scopeNode'])
            ->name('scopes.impact');
        Route::post('scopes/{scopeNode}/move', [ScopeNodeApiController::class, 'move'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:update,scopeNode'])
            ->name('scopes.move');
        Route::patch('scopes/{scopeNode}', [ScopeNodeApiController::class, 'update'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:update,scopeNode'])
            ->name('scopes.update');
        Route::delete('scopes/{scopeNode}', [ScopeNodeApiController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:delete,scopeNode'])
            ->name('scopes.destroy');

        // Access grants (Increment G10b / RBAC §8) — the write surface that makes the Reviewer role
        // assignable, and the first consumer of `forms.collaborators.manage` since it was defined. `store`
        // adds an in-controller `grantCapacity` authorize on top of the route gate: the no-self-grant and
        // anti-amplification rules depend on the capacity and recipient, which middleware cannot bind.
        Route::get('resource-grants', [ResourceGrantApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:viewAny,'.ResourceGrant::class])
            ->name('resource-grants.index');
        Route::post('resource-grants', [ResourceGrantApiController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:create,'.ResourceGrant::class])
            ->name('resource-grants.store');
        Route::delete('resource-grants/{resourceGrant}', [ResourceGrantApiController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_SCOPES, 'can:delete,resourceGrant'])
            ->name('resource-grants.destroy');

        // Audit log (H4 / audit-compliance-logging-spec.md §3) — read-only. Pairs `ability:read:audit_log`
        // (scopes the TOKEN) with `can:viewAny,Audit` (re-checks the acting user's Owner/Admin permission);
        // RLS scopes every row to the tenant. Append-only, so there is no write route. Regenerate openapi.json.
        Route::get('audits', [AuditApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_AUDIT_LOG, 'can:viewAny,'.Audit::class])
            ->name('audits.index');

        // Offline sync (Increment G8b / docs/offline-first-sync-design.md) — the authenticated Group-B channel
        // for future encoder clients that collect offline (the guest PWA uses the public guest endpoints).
        // `form_version_id` is a query/body param, not a bound model, so authorization is `ability:` + RLS.
        // offline_sync gates the offline-collection ENTRY (the schema/manifest fetch). The replay endpoint
        // below is NOT gated on offline_sync — already-collected data is always accepted (never-block, §D4);
        // both remain behind the Group-B api_access gate.
        Route::get('sync/manifest', [SyncManifestController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'feature:offline_sync'])
            ->name('sync.manifest');

        // Idempotent batch replay of queued submissions (per-item results; a partial failure never rolls back
        // its siblings). source = offline_sync; `client_submission_uuid` dedupes a duplicate replay to a no-op.
        Route::post('sync/submissions', [SyncSubmissionController::class, 'store'])
            ->middleware('ability:'.ApiAbilities::WRITE_SUBMISSIONS)
            ->name('sync.submissions');

        // Draft promotion (Increment H9b) — finalize a saved draft (an OCR-staged or manually-saved partial)
        // to `submitted`, recording the acting encoder. A write → write:submissions ability + a SubmissionPolicy
        // can:promote gate on the bound {submission} (the standing rule: a resource-bound Group-B route carries
        // BOTH). RLS scopes the binding to the tenant. Regenerate openapi.json after adding this.
        Route::post('submissions/{submission}/promote', [SubmissionPromoteController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::WRITE_SUBMISSIONS, 'can:promote,submission'])
            ->name('submissions.promote');

        // Webhook endpoints (H13a / webhook-integration-design.md) — the tenant's outbound delivery targets.
        // Every route carries the standing triplet: `ability:manage:webhooks` (scopes the TOKEN) + a
        // WebhookEndpointPolicy `can:` gate (re-checks the acting user's Owner/Admin permission) + the
        // `feature:webhooks` plan gate (Starter+). RLS scopes every query and {webhookEndpoint} binding to the
        // tenant. Static + more-specific segments precede the {webhookEndpoint} patterns. Regenerate openapi.json.
        Route::get('webhooks', [WebhookEndpointController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:viewAny,'.WebhookEndpoint::class, 'feature:webhooks'])
            ->name('webhooks.index');
        Route::post('webhooks', [WebhookEndpointController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:create,'.WebhookEndpoint::class, 'feature:webhooks'])
            ->name('webhooks.store');
        Route::get('webhooks/{webhookEndpoint}/deliveries', [WebhookDeliveryController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:view,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.deliveries.index');
        // H13b: manual redeliver re-enters the pipeline (re-runs SSRF; exempt from re-metering). Nested under
        // {webhookEndpoint} + {webhookDelivery}, both RLS-scoped; gated on the parent endpoint's update ability.
        Route::post('webhooks/{webhookEndpoint}/deliveries/{webhookDelivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:update,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.deliveries.redeliver');
        // H13b: send a synthetic test.ping (synchronous, inline result, persists nothing).
        Route::post('webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:update,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.test');
        // H13b: rotate the signing secret (new plaintext returned once; old valid during the grace window).
        Route::post('webhooks/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:update,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.rotate-secret');
        Route::get('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:view,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.show');
        Route::patch('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:update,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.update');
        Route::delete('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_WEBHOOKS, 'can:delete,webhookEndpoint', 'feature:webhooks'])
            ->name('webhooks.destroy');

        // Native connectors (H15a / ADR-0009) — the tenant's OAuth grants and the delivery rules on them.
        // Standing triplet on every route: `ability:manage:integrations` (scopes the TOKEN) + a
        // ConnectionPolicy `can:` gate (re-checks the acting user's Owner/Admin permission) + the
        // `feature:native_connectors` plan gate (Starter+, ADR-0008 §D7).
        //
        // There is deliberately no POST/PATCH for a CONNECTION: a grant can only be created by the
        // interactive OAuth flow (GET /integrations/{provider}/connect on the tenant host → the provider's
        // consent screen → the central-domain callback), so a bearer API can start nothing and never accepts
        // a token as input. Subscription routes are nested under {connection} so authorization is decided on
        // the grant that owns the rule. Static + more-specific segments precede the {subscription} patterns.
        // Regenerate openapi.json.
        Route::get('connections', [ConnectionController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:viewAny,'.Connection::class, 'feature:native_connectors'])
            ->name('connections.index');
        Route::get('connections/{connection}/subscriptions', [ConnectionSubscriptionController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:view,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.index');
        Route::post('connections/{connection}/subscriptions', [ConnectionSubscriptionController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:update,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.store');
        Route::get('connections/{connection}/subscriptions/{subscription}/deliveries', [ConnectionSubscriptionController::class, 'deliveries'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:view,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.deliveries');
        Route::get('connections/{connection}/subscriptions/{subscription}', [ConnectionSubscriptionController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:view,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.show');
        Route::patch('connections/{connection}/subscriptions/{subscription}', [ConnectionSubscriptionController::class, 'update'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:update,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.update');
        Route::delete('connections/{connection}/subscriptions/{subscription}', [ConnectionSubscriptionController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:update,connection', 'feature:native_connectors'])
            ->name('connections.subscriptions.destroy');
        Route::get('connections/{connection}', [ConnectionController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:view,connection', 'feature:native_connectors'])
            ->name('connections.show');
        Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_INTEGRATIONS, 'can:delete,connection', 'feature:native_connectors'])
            ->name('connections.destroy');

        // Cross-form analytics (H24a / ADR-0011). Standing triplet on every route: `ability:read:analytics`
        // (scopes the TOKEN — a NEW ability, never a widening, so no already-minted token gains analytics
        // access) + a SavedReportViewPolicy `can:` gate (re-checks the acting user's real permission) + the
        // `feature:advanced_analytics` plan gate (Business+, held from sale per ADR-0008 §D6).
        //
        // The report and question routes have NO model of their own, so they gate on
        // `can:viewAny,SavedReportView` — whose predicate is exactly the read:analytics ability map, so the
        // token scope and the permission check agree by construction. `can:viewAny,Submission::class` was
        // rejected: it authorizes on `submissions.view`, a widening of a different capability.
        //
        // Static + more-specific segments precede the {savedReportView} patterns. Regenerate openapi.json.
        Route::get('analytics/report', [AnalyticsReportController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.report');
        Route::get('analytics/report/export', [AnalyticsReportController::class, 'export'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.report.export');
        Route::get('analytics/questions', [AnalyticsQuestionController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.questions.index');
        Route::get('analytics/questions/{key}', [AnalyticsQuestionController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.questions.show');

        Route::get('analytics/views', [SavedReportViewController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:viewAny,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.views.index');
        Route::post('analytics/views', [SavedReportViewController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:create,'.SavedReportView::class, 'feature:advanced_analytics'])
            ->name('analytics.views.store');
        // The bound-model gates below are load-bearing rather than ceremonial: saved_report_views carries
        // `strict` RLS, which scopes to the TENANT only, so the policy's owner check is the ONLY thing
        // stopping one member reading or deleting a colleague's view.
        Route::get('analytics/views/{savedReportView}', [SavedReportViewController::class, 'show'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:view,savedReportView', 'feature:advanced_analytics'])
            ->name('analytics.views.show');
        Route::patch('analytics/views/{savedReportView}', [SavedReportViewController::class, 'update'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:update,savedReportView', 'feature:advanced_analytics'])
            ->name('analytics.views.update');
        Route::delete('analytics/views/{savedReportView}', [SavedReportViewController::class, 'destroy'])
            ->middleware(['ability:'.ApiAbilities::READ_ANALYTICS, 'can:delete,savedReportView', 'feature:advanced_analytics'])
            ->name('analytics.views.destroy');

        // Custom domains (H22a / ADR-0012). `manage:domains` is a NEW ability — an already-minted
        // `manage:settings` token must not retroactively gain the power to point the hostname a tenant's
        // respondents visit somewhere else — mapped onto the EXISTING `tenant.settings.manage` permission,
        // whose audience (Owner + Admin) is already exactly right. The `can:` gate is that bare permission
        // rather than a policy: there is no per-instance authorization question here, and the resource has
        // no policy (see below).
        //
        // `feature:custom_domain` (Business+) gates the WRITES ONLY. index/destroy stay ungated, verbatim
        // the api_access precedent above — and it matters more here: a tenant downgraded off Business keeps
        // a LIVE, resolving hostname, so it must still be able to see it and take it down.
        //
        // {domain} IS NOT A BOUND MODEL. `domains` is RLS-exempt, so implicit binding would resolve any
        // tenant's row with no database backstop under the policy; the controller resolves the hostname
        // with an explicit tenant_id filter instead. The parameter is constrained to a hostname shape so a
        // path segment carrying a slash or a scheme 404s at the router rather than reaching the query.
        //
        // THERE IS DELIBERATELY NO ACTIVATE ROUTE — putting a verified domain into service is
        // `php artisan domains:activate`, run by whoever installed the TLS certificate (ADR-0012).
        Route::get('domains', [DomainApiController::class, 'index'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_DOMAINS, 'can:tenant.settings.manage'])
            ->name('domains.index');
        Route::post('domains', [DomainApiController::class, 'store'])
            ->middleware(['ability:'.ApiAbilities::MANAGE_DOMAINS, 'can:tenant.settings.manage', 'feature:custom_domain'])
            ->name('domains.store');
        Route::post('domains/{domain}/verify', [DomainApiController::class, 'verify'])
            ->where('domain', '[A-Za-z0-9.-]+')
            ->middleware(['ability:'.ApiAbilities::MANAGE_DOMAINS, 'can:tenant.settings.manage', 'feature:custom_domain'])
            ->name('domains.verify');
        // H22b — choose which LIVE host respondent links are built on. Read the paragraph above before
        // reading this as the activate endpoint that deliberately does not exist: it refuses anything not
        // already live, so it selects among hosts an operator put into service and cannot produce one.
        Route::post('domains/{domain}/primary', [DomainApiController::class, 'primary'])
            ->where('domain', '[A-Za-z0-9.-]+')
            ->middleware(['ability:'.ApiAbilities::MANAGE_DOMAINS, 'can:tenant.settings.manage', 'feature:custom_domain'])
            ->name('domains.primary');
        Route::delete('domains/{domain}', [DomainApiController::class, 'destroy'])
            ->where('domain', '[A-Za-z0-9.-]+')
            ->middleware(['ability:'.ApiAbilities::MANAGE_DOMAINS, 'can:tenant.settings.manage'])
            ->name('domains.destroy');
    });

// ── Group C: public guest runtime (Increment F5) — UNAUTHENTICATED; tenant resolved from the signed ──────
//    share token, not a subdomain or session. No `web` (so no session/CSRF — the guest SPA is a separate
//    cross-origin app, architecture §8) and no PreventAccessFromCentralDomains (reachable host-agnostically
//    for embeds). EstablishGuestTenantContext verifies the token → sets the RLS tenant → stashes the decoded
//    payload; the controllers resolve the pinned form/version from it and funnel a `source=guest` submission
//    into the same SubmissionPipeline (Group B's ordering dance is unnecessary — {shareToken} is a plain
//    string, not a bound model, so nothing depends on running before SubstituteBindings).
Route::prefix('api/v1/public')
    ->name('api.v1.public.')
    ->middleware([
        'throttle:guest',
        EstablishGuestTenantContext::class,
        SubstituteBindings::class,
    ])
    ->group(function (): void {
        Route::get('f/{shareToken}', [PublicFormSchemaController::class, 'show'])->name('forms.schema');
        Route::post('f/{shareToken}/submissions', [GuestSubmissionController::class, 'store'])->name('submissions.store');
        // Media upload (Increment G6) — a respondent stages a file mid-form (before submit) against the
        // pinned version's media field; the returned AttachmentRef rides the answer document at submit.
        Route::post('f/{shareToken}/attachments', [GuestAttachmentController::class, 'store'])->name('attachments.store');

        // Save-and-resume draft upsert (Increment H9b) — create/overwrite a durable server draft and return a
        // resume token scoped to its submissions.id. Gated on save_and_resume (Starter+): a paid convenience,
        // not the respondent's final answer, so this does not breach never-block (submit above stays ungated).
        // The feature middleware runs after EstablishGuestTenantContext, so tenant context is set. Regenerate
        // openapi.json after adding this.
        Route::post('f/{shareToken}/draft', [GuestDraftController::class, 'store'])
            ->middleware('feature:save_and_resume')->name('drafts.store');
    });

// ── Group C (resume): guest draft RESUME — UNAUTHENTICATED; tenant + the target draft submissions.id resolved ─
//    from the signed resume token (not a share token), verified pre-RLS by EstablishGuestDraftContext. Same
//    gate as the draft-save channel; the finalize path stays on the share-token submit route above.
Route::prefix('api/v1/public')
    ->name('api.v1.public.')
    ->middleware([
        'throttle:guest',
        EstablishGuestDraftContext::class,
        SubstituteBindings::class,
        'feature:save_and_resume',
    ])
    ->group(function (): void {
        Route::get('drafts/{resumeToken}', [GuestDraftResumeController::class, 'show'])->name('drafts.resume');
    });
