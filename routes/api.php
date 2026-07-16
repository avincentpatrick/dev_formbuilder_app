<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\FormApiController;
use App\Http\Controllers\Api\V1\FormVersionApiController;
use App\Http\Controllers\Api\V1\FormXlsformApiController;
use App\Http\Controllers\Api\V1\SyncManifestController;
use App\Http\Controllers\Api\V1\SyncSubmissionController;
use App\Http\Controllers\Api\V1\TenantApiController;
use App\Http\Controllers\Public\GuestAttachmentController;
use App\Http\Controllers\Public\GuestSubmissionController;
use App\Http\Controllers\Public\PublicFormSchemaController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EstablishGuestTenantContext;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Models\Form;
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
        Route::post('auth/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
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
        'throttle:api',
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
            ->middleware(['ability:'.ApiAbilities::READ_FORMS, 'can:view,form'])
            ->name('forms.versions.xlsform');

        // XLSForm import (Increment G7b / docs/xlsform-interop-spec.md §5) — destructively replace the form's
        // current draft with the uploaded .xlsx. A write → WRITE_FORMS + can:update,form (mirroring publish).
        Route::post('forms/{form}/draft/xlsform-import', [FormXlsformApiController::class, 'import'])
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'can:update,form'])
            ->name('forms.xlsform.import');

        // Publish the form's current draft (docs/form-versioning-schema-migration.md §3.2). The URL names
        // the draft version; the controller rejects a non-draft {version} before delegating to PublishService.
        Route::post('forms/{form}/versions/{version}/publish', [FormVersionApiController::class, 'publish'])
            ->scopeBindings()
            ->middleware(['ability:'.ApiAbilities::WRITE_FORMS, 'can:publish,form'])
            ->name('forms.versions.publish');

        // Offline sync (Increment G8b / docs/offline-first-sync-design.md) — the authenticated Group-B channel
        // for future encoder clients that collect offline (the guest PWA uses the public guest endpoints).
        // `form_version_id` is a query/body param, not a bound model, so authorization is `ability:` + RLS.
        Route::get('sync/manifest', [SyncManifestController::class, 'show'])
            ->middleware('ability:'.ApiAbilities::READ_FORMS)
            ->name('sync.manifest');

        // Idempotent batch replay of queued submissions (per-item results; a partial failure never rolls back
        // its siblings). source = offline_sync; `client_submission_uuid` dedupes a duplicate replay to a no-op.
        Route::post('sync/submissions', [SyncSubmissionController::class, 'store'])
            ->middleware('ability:'.ApiAbilities::WRITE_SUBMISSIONS)
            ->name('sync.submissions');
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
    });
