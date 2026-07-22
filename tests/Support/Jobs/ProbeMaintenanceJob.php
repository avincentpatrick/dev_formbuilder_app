<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\MaintenanceJob;
use App\Models\Attachment;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * A cross-tenant MaintenanceJob that records the context it observed (ADR-0007 §D3/§D4).
 *
 * The load-bearing observation is `visible`: a MaintenanceJob must see ZERO rows from a tenant-scoped
 * table, because it runs with no tenant context. If it sees rows, the §D4 listener failed to clear a
 * predecessor's static and BelongsToTenant is injecting a stale tenant id — the exact defect
 * ADR-0007 §Context 6 documents. `tenants` is separately visible because it is RLS-exempt.
 *
 * A no-arg constructor is required: a MaintenanceJob is scheduled by class-string and
 * Schedule::job() resolves it through the container.
 */
final class ProbeMaintenanceJob extends MaintenanceJob
{
    /**
     * @var list<array{static: ?string, team: int|string|null, visible: int, activeTenants: list<string>}>
     */
    public static array $observations = [];

    public static function reset(): void
    {
        self::$observations = [];
    }

    /**
     * @return array{static: ?string, team: int|string|null, visible: int, activeTenants: list<string>}|null
     */
    public static function last(): ?array
    {
        return self::$observations === [] ? null : self::$observations[array_key_last(self::$observations)];
    }

    protected function sweep(): void
    {
        $active = [];

        foreach ($this->activeTenants() as $tenant) {
            $active[] = (string) $tenant->getKey();
        }

        self::$observations[] = [
            'static' => TenantContext::currentTenantId(),
            'team' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
            'visible' => Attachment::query()->count(),
            'activeTenants' => $active,
        ];
    }
}
