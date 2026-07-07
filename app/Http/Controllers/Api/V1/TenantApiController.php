<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The current tenant profile over /api/v1 (Increment E). The tenant is the RLS-exempt discriminator,
 * resolved from the subdomain by stancl and bound in the container; billing actions stay in the Cashier
 * Admin UI (technical-architecture §7.1).
 */
final class TenantApiController extends Controller
{
    /**
     * Fetch the current tenant profile.
     */
    public function show(): TenantResource
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return TenantResource::make($tenant);
    }
}
