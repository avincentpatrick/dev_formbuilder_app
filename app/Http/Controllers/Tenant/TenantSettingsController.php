<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateDraftSettingsRequest;
use App\Models\Tenant;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Tenant-level settings (Increment H10) — the Owner/Admin surface for org-wide configuration, distinct from
 * the per-USER {@see PreferencesController} (profile / appearance). The first setting is the draft-expiry
 * window; the controller is named generically so later tenant settings land beside it.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate. The write targets the SUBDOMAIN-RESOLVED
 * current tenant only — stancl binds it under the Tenant contract (the same instance the `tenant()` helper
 * reads) — and never a tenant id from the request body: `tenants` is RLS-exempt, so this controller-side
 * scoping is what prevents a cross-tenant write. `forceFill` mirrors {@see FormService}'s
 * guarded-column writes.
 */
final class TenantSettingsController extends Controller
{
    /**
     * Partial update of the tenant's draft settings (currently `draft_ttl_days`). Every rule is `sometimes`,
     * so this writes only the keys the caller sent — see {@see UpdateDraftSettingsRequest}.
     */
    public function updateDrafts(UpdateDraftSettingsRequest $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        $tenant->forceFill($request->toColumns())->save();

        return back()->with('status', 'draft-settings-updated');
    }
}
