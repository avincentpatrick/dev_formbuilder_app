<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\AuditEvent;
use App\Http\Controllers\Tenant\TenantSettingsController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-level settings writes (Increment I2), extracted from {@see TenantSettingsController},
 * which had been writing `$tenant->forceFill(...)->save()` inline with no transaction — the last
 * tenant-settings write in the app that did not go through a service, and therefore the last one that
 * could not be audited atomically ({@see AuditLogger} requires the row and the change to commit together).
 *
 * Three reasons it is a service and not a fatter controller: every audited write in this codebase lives in
 * one; `scripts/controller-gate.php` caps controller method complexity at 10 and a controller that opens a
 * transaction and builds old/new arrays is exactly the shape that gate exists to stop growing; and it
 * mirrors `BrandingController → TenantBrandingService`, so drafts being controller-resident was the
 * inconsistency rather than the fix.
 *
 * ── `auditable_type = 'settings'`, `auditable_id = tenants.id` — and that is PERMANENT ─────────────────
 * Spec §1 assigns tenant configuration changes to the `settings` alias (`tenant` is explicitly limited to
 * status and ownership changes, "not every cosmetic tenant-settings edit"). There is no `settings` table
 * yet — I5 builds one — and the rule is pinned in §1 now precisely so I5 does not key its new rows on a
 * settings-row uuid: a per-tenant singleton whose history was split across two `auditable_id`s would be
 * unqueryable by `audits_tenant_auditable_idx`, which is the index the viewer's "this resource's history"
 * filter rides on.
 */
final class TenantSettingsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Partial update of the tenant's draft settings.
     *
     * @param  array<string, mixed>  $columns  ONLY the keys the request actually sent
     */
    public function updateDraftSettings(Tenant $tenant, array $columns, ?User $actor = null): Tenant
    {
        // The one place a write is not recorded, and it is not a "no-change" exemption: a request that sent
        // no field AT ALL is an empty PATCH, not an act. AuditLogger's standing rule — a no-change save
        // still emits a row, because "nobody touched it" and "somebody opened it and saved" are different
        // facts — holds for every other path through here.
        if ($columns === []) {
            return $tenant;
        }

        return DB::transaction(function () use ($tenant, $columns, $actor): Tenant {
            // ⚠️ REFRESH FIRST, and it is not defensive. A `tenants` row can reach this method PARTIALLY
            // HYDRATED — a model built by `Tenant::create([...])` holds only the attributes that were
            // passed, so a column filled by its DATABASE DEFAULT (`draft_ttl_days` is one) is simply absent
            // from `getOriginal()`. Snapshotting that model records NO `old` key at all, and
            // {@see \App\Support\Audit\AuditDiff} faithfully renders the result as "(absent) → 45" when the
            // truth is "30 → 45". The ledger would not be wrong-ish; it would be stating that a value did
            // not exist before, which is a different and false claim. One SELECT on a settings PATCH is not
            // a cost worth trading a true ledger for.
            $tenant->refresh();

            // Keyed off what was SENT, not the whole row. A request carrying only `draft_ttl_days` produces
            // a one-key diff rather than a full-row snapshot — which is what keeps this readable as I5 grows
            // this surface from one setting to a dozen.
            $old = Arr::only($tenant->getOriginal(), array_keys($columns));

            $tenant->forceFill($columns)->save();

            $this->audit->record(
                AuditEvent::Updated,
                'settings',
                (string) $tenant->getKey(),
                old: $old,
                new: $columns,
                actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
            );

            return $tenant;
        });
    }
}
