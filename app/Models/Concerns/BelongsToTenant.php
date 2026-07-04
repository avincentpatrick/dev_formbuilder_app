<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The ORM tenant-scoping layer (ADR-0002 §D3, data-access row). Applied to every tenant-scoped
 * model, it (a) injects a `where tenant_id = <current tenant>` predicate into every Eloquent query
 * and (b) auto-fills tenant_id from the current context on create.
 *
 * This is a convenience + first line of defense, DELIBERATELY NOT the guarantee — it is invisible to
 * raw DB:: queries, a forgotten trait on a new model, or a report that drops scopes. PostgreSQL RLS
 * (the FORCE'd policies from {@see TenantIsolation}) is what actually enforces isolation. The two are
 * kept in agreement so tests can assert the scope never shows what RLS hides.
 *
 * Models using this trait should also `implements TenantScoped` — the trait already provides the
 * method, so it costs nothing and lets the boot closures resolve the tenant column type-safely.
 */
trait BelongsToTenant
{
    /** Named identifier so the scope can be removed with withoutGlobalScope(self::$tenantScope). */
    public static string $tenantScope = 'tenant';

    /** A tenant id that can never exist, used to force "no rows" when there is no context. */
    private const NO_TENANT_SENTINEL = '00000000-0000-0000-0000-000000000000';

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(static::$tenantScope, function (Builder $builder): void {
            // getModel() resolves to the using model, which always has getTenantColumn() (this trait).
            $model = $builder->getModel();

            // No context ⇒ match nothing, mirroring RLS's fail-closed default rather than silently
            // returning every tenant's rows.
            $builder->where(
                $model->qualifyColumn($model->getTenantColumn()),
                TenantContext::currentTenantId() ?? self::NO_TENANT_SENTINEL
            );
        });

        static::creating(function (Model $model): void {
            if (! $model instanceof TenantScoped) {
                return;
            }

            $column = $model->getTenantColumn();

            if ($model->getAttribute($column) === null && TenantContext::currentTenantId() !== null) {
                $model->setAttribute($column, TenantContext::currentTenantId());
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * Escape hatch for the rare legitimate cross-tenant read (platform/super-admin paths). RLS still
     * applies underneath — dropping the ORM scope never widens what the database will actually return.
     *
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(static::$tenantScope);
    }
}
