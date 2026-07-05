<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Resolves the current tenant bound by stancl/tenancy's identification middleware. Only safe to call
 * inside the subdomain route groups (where the tenant is guaranteed bound); it aborts rather than
 * returning null so a mis-registered route surfaces loudly instead of leaking a null into RLS.
 */
trait ResolvesTenant
{
    protected function currentTenant(): Tenant
    {
        $tenant = app(TenantContract::class);
        abort_unless($tenant instanceof Tenant, 500, 'No tenant resolved for this request.');

        return $tenant;
    }
}
