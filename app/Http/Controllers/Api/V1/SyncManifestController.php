<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\FormVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncManifestRequest;
use App\Http\Resources\Api\V1\SyncManifestResource;
use App\Models\FormVersion;

/**
 * The authenticated offline-sync manifest surface (Increment G8b, docs/offline-first-sync-design.md §2 /
 * api-specification.md §4) — the Group-B analog of the guest schema fetch, for future authenticated encoder
 * clients that collect offline. Returns the pinned version's renderable snapshot + checksum so a device can
 * cache it and keep filling against that exact version even after a republish (§6.2 "no mid-collection
 * schema surprise"). The version is resolved by id under RLS (a cross-tenant / unknown id 404s); a draft that
 * was never published has no snapshot, so only published or superseded versions resolve.
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

        return SyncManifestResource::make($version);
    }
}
