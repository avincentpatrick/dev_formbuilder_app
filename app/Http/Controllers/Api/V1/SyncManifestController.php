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
     */
    public function show(SyncManifestRequest $request): SyncManifestResource
    {
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
