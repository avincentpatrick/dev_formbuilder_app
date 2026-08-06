<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\AuditEvent;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Entitlements\EntitlementService;
use App\Support\Audit\AuditLogger;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The tenant half of the `settings` store (data-dictionary §20, PRD Feature #10) — Increment I5. Every
 * read and every write of a tenant-scoped setting goes through here; nothing else queries {@see Setting}.
 *
 * Bound `scoped()` (per-request), not `singleton()`: a process-lifetime memo would mean a toggle flipped in
 * the admin UI does not take effect until the worker restarts, and one Pest test's rows would bleed into
 * the next. Memoized per TENANT id rather than globally, mirroring {@see EntitlementService}'s memo — the
 * platform-settings write path and the registration gate both read a tenant that is not the ambient one.
 *
 * ⚠️ `whereNotNull('tenant_id')` IS LOAD-BEARING, NOT DEFENSIVE. `settings` is nullable_global, so its
 * SELECT policy deliberately reveals PLATFORM rows to every tenant. Without that predicate a platform
 * `maintenance.enabled` row would arrive in this map indistinguishable from a tenant's own, and one
 * operator's platform toggle would read as every tenant's setting.
 *
 * ── The table is SPARSE; this class is where that stops being visible ──────────────────────────────────
 * An absent row means "default", and the default lives on {@see SettingKey} (or, for `modules.*` — an open
 * namespace keyed by {@see ToggleableModules} rather than a fixed case list — it is "enabled", since a
 * tenant that has expressed no opinion should get exactly what its plan grants). Callers always receive a
 * RESOLVED answer, so no surface has to null-coalesce and no two surfaces can pick different fallbacks.
 *
 * ── The audit key is PINNED and is not the settings row's own id ───────────────────────────────────────
 * `auditable_type = 'settings'`, `auditable_id = tenants.id` — permanently, including now that a `settings`
 * table exists (audit spec §1, data-dictionary §13). I2 wrote every tenant-settings audit that way while
 * this table was still hypothetical, precisely so I5 would not re-key onto a per-row uuid and split one
 * tenant's settings history across a dozen `auditable_id`s — which is unqueryable by
 * `audits_tenant_auditable_idx`, the index the viewer's "this resource's history" filter rides on.
 */
final class TenantSettingRegistry
{
    /** @var array<string, array<string, mixed>> tenant id ⇒ (key ⇒ decoded stored value) */
    private array $memo = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every stored setting for the ambient tenant, decoded. Empty (and no query) off-tenant.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return [];
        }

        return $this->memo[$tenantId] ??= $this->fetch();
    }

    /**
     * Every stored setting for a NAMED tenant, which is not always the ambient one.
     *
     * The caller that forces this to exist is the registration gate: Fortify's `/register` carries `web`,
     * `RequirePlatformHost` and `AppSecurityHeaders` and **no tenancy middleware at all**, so there is no
     * tenant GUC on the request that most needs to read `registration.invite_only`. Under nullable_global's
     * SELECT policy that means only PLATFORM rows are visible, a tenant's stored value is invisible rather
     * than wrong, and the sparse default (invite-only) silently wins forever. It fails CLOSED, which is why
     * it would have read as a feature bug rather than as a hole.
     *
     * ⚠️ THE TRANSACTION IS MANDATORY. {@see TenantContext::applyLocal()} is `SET LOCAL` — outside a
     * transaction it is a silent no-op, and this method would then return the same empty map it exists to
     * avoid. The prior context is restored in `finally`, the {@see SuperAdminService}
     * adopt-and-restore precedent.
     *
     * @return array<string, mixed>
     */
    public function forTenant(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->getKey();

        if (array_key_exists($tenantId, $this->memo)) {
            return $this->memo[$tenantId];
        }

        if (TenantContext::currentTenantId() === $tenantId) {
            return $this->all();
        }

        $savedTenant = TenantContext::currentTenantId();
        $savedUser = TenantContext::currentUserId();

        return DB::transaction(function () use ($tenantId, $savedTenant, $savedUser): array {
            TenantContext::applyLocal($tenantId);

            try {
                return $this->memo[$tenantId] = $this->fetch();
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /** The resolved value of a known key for the ambient tenant. */
    public function get(SettingKey $key): bool|string
    {
        $value = $this->all()[$key->value] ?? null;
        $default = $key->default();

        if (is_bool($default)) {
            return is_bool($value) ? $value : $default;
        }

        return is_string($value) ? $value : $default;
    }

    /** The resolved value of a known key for a NAMED tenant — see {@see forTenant()} for why. */
    public function getFor(Tenant $tenant, SettingKey $key): bool|string
    {
        $value = $this->forTenant($tenant)[$key->value] ?? null;
        $default = $key->default();

        if (is_bool($default)) {
            return is_bool($value) ? $value : $default;
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * Whether a capability module is switched on for the ambient tenant.
     *
     * "On" is the default, and the asymmetry with the rest of the store is the point: this answer is ANDed
     * with the plan's own flag in {@see EntitlementService::feature()}, so a tenant that has never opened
     * the Modules card gets exactly what it pays for, and the toggle can only ever subtract.
     */
    public function moduleEnabled(string $module): bool
    {
        $value = $this->all()[ToggleableModules::settingKey($module)] ?? null;

        return ! is_bool($value) || $value;
    }

    /**
     * Write one or more of a tenant's settings, and record ONE audit row for the change.
     *
     * @param  array<string, bool|string>  $values  key ⇒ new value, already validated by the caller
     */
    public function put(Tenant $tenant, array $values, ?User $actor = null): void
    {
        // An empty PATCH is not an act. Same rule (and same reason) as TenantSettingsService: a NO-CHANGE
        // save still emits a row, because "nobody touched it" and "somebody opened it and saved" are
        // different facts — but a request that sent no field at all is neither.
        if ($values === []) {
            return;
        }

        $tenantId = (string) $tenant->getKey();

        DB::transaction(function () use ($tenantId, $values, $actor): void {
            // Resolved, not raw: the ledger should read "invite_only: true → false", never
            // "(absent) → false", which claims the setting did not exist before. The I2 refresh-first
            // argument, applied to a sparse table instead of to DB-default columns.
            $before = $this->fetch();
            $old = [];

            foreach ($values as $key => $value) {
                $old[$key] = $this->resolve($before, $key);

                Setting::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $actor?->getKey()],
                );
            }

            $this->audit->record(
                AuditEvent::Updated,
                'settings',
                $tenantId,
                old: $old,
                new: $values,
                actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
            );

            unset($this->memo[$tenantId]);
        });
    }

    /** Drop the memo — for tests, and for a caller that has just written through another path. */
    public function forget(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$tenantId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        /** @var array<string, mixed> $rows */
        $rows = Setting::query()
            ->whereNotNull('tenant_id')
            ->pluck('value', 'key')
            ->map(static fn (mixed $raw): mixed => self::decode($raw))
            ->all();

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function resolve(array $stored, string $key): mixed
    {
        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        $known = SettingKey::tryFrom($key);

        if ($known !== null) {
            return $known->default();
        }

        // `modules.*` — see moduleEnabled(). Anything else is a key nobody has claimed, and null is the
        // honest answer rather than a guess.
        return str_starts_with($key, 'modules.') ? true : null;
    }

    /**
     * `settings.value` is a schemaless jsonb with no Eloquent cast (see {@see Setting}), so it arrives as
     * the raw json text. Decoding here, once, is what lets every caller receive a real bool or string.
     */
    private static function decode(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        return json_decode($raw, true);
    }
}
