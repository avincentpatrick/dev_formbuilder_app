<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAccessSettingsRequest;
use App\Http\Requests\Tenant\UpdateDraftSettingsRequest;
use App\Http\Requests\Tenant\UpdateMaintenanceSettingsRequest;
use App\Http\Requests\Tenant\UpdateModuleSettingsRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Tenancy\TenantSettingsService;
use Illuminate\Http\RedirectResponse;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Tenant-level settings (Increment H10) — the Owner/Admin surface for org-wide configuration, distinct from
 * the per-USER {@see PreferencesController} (profile / appearance / notifications). The first setting was the
 * draft-expiry window; the controller was named generically so later tenant settings would land beside it,
 * and I5 is where that happened: Access, Maintenance and Modules (PRD Feature #10) are here rather than on
 * `PreferencesController`, which already carries four dependencies and two personal-preference surfaces.
 *
 * The four writes reach TWO stores, and which one a setting uses is a property of where it is READ, not of
 * where it is written: maintenance mode is a `tenants` COLUMN because the guest runtime consults it on every
 * public form render, while Access and Modules are `settings` ROWS read only inside the authenticated app.
 * {@see TenantSettingsService} and {@see TenantSettingRegistry} own one store each and emit the SAME audit
 * alias, so the ledger shows one resource with one history either way.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate. The write targets the SUBDOMAIN-RESOLVED
 * current tenant only — stancl binds it under the Tenant contract (the same instance the `tenant()` helper
 * reads) — and never a tenant id from the request body: `tenants` is RLS-exempt, so this controller-side
 * scoping is what prevents a cross-tenant write.
 *
 * Increment I2 moved the write itself into {@see TenantSettingsService} (mirroring
 * `BrandingController → TenantBrandingService`), because the audit row must be atomic with the change and a
 * controller is the wrong place to open a transaction — see that class for why the rule and its one
 * exemption are written down there rather than here. `forceFill` still mirrors {@see FormService}'s
 * guarded-column writes; it just happens a layer down.
 */
final class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly TenantSettingRegistry $registry,
    ) {}

    /**
     * Partial update of the tenant's draft settings (currently `draft_ttl_days`). Every rule is `sometimes`,
     * so this writes only the keys the caller sent — see {@see UpdateDraftSettingsRequest}.
     */
    public function updateDrafts(UpdateDraftSettingsRequest $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);
        /** @var User $user */
        $user = $request->user();

        $this->settings->updateDraftSettings($tenant, $request->toColumns(), $user);

        return back()->with('status', 'draft-settings-updated');
    }

    /** Access — who may join this workspace (I5). A `settings` ROW, written through the registry. */
    public function updateAccess(UpdateAccessSettingsRequest $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);
        /** @var User $user */
        $user = $request->user();

        $this->registry->put($tenant, $request->toSettings(), $user);

        return back()->with('status', 'access-settings-updated');
    }

    /**
     * Maintenance — pause the PUBLIC runtime (I5). Tenant COLUMNS, not a `settings` row, so the guest
     * runtime reads the flag off the already-resolved tenant with no extra query; see
     * `2026_08_06_000004_add_maintenance_to_tenants.php` for the full argument.
     */
    public function updateMaintenance(UpdateMaintenanceSettingsRequest $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);
        /** @var User $user */
        $user = $request->user();

        $this->settings->updateMaintenance($tenant, $request->toColumns(), $user);

        return back()->with('status', 'maintenance-settings-updated');
    }

    /** Modules — switch one capability off for this workspace (I5). A `settings` ROW. */
    public function updateModules(UpdateModuleSettingsRequest $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);
        /** @var User $user */
        $user = $request->user();

        $this->registry->put($tenant, $request->toSettings(), $user);

        // The module toggle is the third layer of EntitlementService::feature(), and that service memoizes
        // per request — without this the redirect's own render would still gate on the pre-write answer.
        app(EntitlementService::class)->forget((string) $tenant->getKey());

        return back()->with('status', 'module-settings-updated');
    }
}
