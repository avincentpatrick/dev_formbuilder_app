<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\FormVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncManifestRequest;
use App\Http\Resources\Api\V1\SyncManifestResource;
use App\Models\Form;
use App\Models\FormVersion;
use App\Policies\FormPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The authenticated offline-sync manifest surface (Increment G8b, docs/offline-first-sync-design.md §2 /
 * api-specification.md §4) — the Group-B analog of the guest schema fetch, for future authenticated encoder
 * clients that collect offline. Returns the pinned version's renderable snapshot + checksum so a device can
 * cache it and keep filling against that exact version even after a republish (§6.2 "no mid-collection
 * schema surprise"). The version is resolved by id under RLS (a cross-tenant / unknown id 404s); a draft that
 * was never published has no snapshot, so only published or superseded versions resolve.
 *
 * ── THE READ TWIN OF THE SIBLING ROUTE'S GAP, CLOSED IN THE SAME INCREMENT (M13) ────────────────────────
 * Like `POST sync/submissions` next to it, this route is **not** resource-bound — `form_version_id` arrives
 * as a query parameter, so there is nothing for a `can:` middleware to bind to and the route file's standing
 * rule silently did not apply. `ability:read:forms` plus RLS was the whole of the authorization, and the
 * payload is the COMPLETE renderable schema: every section, field, label, hint, choice, validation and
 * expression the tenant has authored on that form. `GET /api/v1/forms/{form}/versions/{version}` returns the
 * same snapshot behind `can:view,form`, so the two doors onto one artefact disagreed — and `read:forms` maps
 * to `forms.create` / `forms.edit.any` / `forms.edit.own`, which makes the exposed principal a **Form
 * Editor reading every other form in the workspace**, the exact scoping G10a exists to enforce.
 *
 * The gate is therefore applied here, against the same {@see FormPolicy::view()} the bound-model route uses,
 * so the two doors now express one rule. It runs AFTER the version resolve on purpose: an unknown or
 * cross-tenant id keeps 404-ing rather than becoming a 403 that would confirm the row exists.
 */
final class SyncManifestController extends Controller
{
    /**
     * Get the offline sync manifest for a pinned form version.
     *
     * @throws AuthorizationException The caller holds `read:forms` but may not view this form.
     */
    public function show(SyncManifestRequest $request): SyncManifestResource
    {
        // ⛔ M68 — THE @throws TAG ABOVE IS THE DOCUMENTED CONTRACT, NOT COMMENTARY, AND THIS NOTE IS
        // DELIBERATELY NOT IN THE DOCBLOCK WITH IT. Scramble publishes an action's docblock DESCRIPTION
        // as the operation's `description` in `openapi.json`, so an explanation of the machinery placed
        // there ships into the public API document and then drifts the Contract gate the moment Pint
        // realigns it (measured in M67, one route over).
        //
        // WHY THE TAG IS LOAD-BEARING: Scramble infers a route's 403 from `can:` / `Authorize::`
        // MIDDLEWARE only (`ErrorResponsesExtension`, v0.13.30) and does not trace a
        // `Gate::forUser()->authorize()` call in an action body. This route has no bindable model — the
        // version arrives as a query parameter, which is the whole reason the gate is in the controller
        // — so the inference had nothing to see and the 403 was undocumented for the life of the route.
        // `Infer\Handler\PhpDocHandler::leave()` reads this tag into the method's exception list, and
        // Scramble's own `AuthorizationExceptionToResponseExtension` — which
        // {@see \App\Support\OpenApi\ApiAuthorizationErrorResponse} extends and M56 already registered —
        // renders it through the SHARED 403 component. So no new extension exists for this and none
        // should: the `$ref` is the one 113 other responses already use.
        //
        // ⚠️ THE SIBLING ROUTE IS NOT THE SAME DEFECT AND MUST NOT GET THE SAME FIX.
        // `POST sync/submissions` answers a per-item `error.code: "forbidden"` inside a **200** body, not
        // a 403 — see that controller's own note. Documenting a 403 there would publish a status it has
        // never returned; its shape is the `SyncSubmissionResultResource` row in the backlog.
        $version = FormVersion::query()
            ->whereKey($request->formVersionId())
            ->whereIn('status', [FormVersionStatus::Published, FormVersionStatus::Superseded])
            ->firstOrFail();

        // `form_versions` carries no soft delete while `forms` does, so a version can outlive its form.
        // `findOrFail` rather than the `form` relation's null: a deleted form is not something anyone can be
        // authorized against, and 404 is what the bound-model route already answers for it.
        $form = Form::query()->findOrFail($version->form_id);

        Gate::forUser($request->user())->authorize('view', $form);

        return SyncManifestResource::make($version);
    }
}
