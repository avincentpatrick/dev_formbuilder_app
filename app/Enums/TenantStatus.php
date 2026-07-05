<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tenant lifecycle state (ADR-0002 §D1; RBAC §9 super-admin console scope). Stored as varchar on the
 * central `tenants` row. `suspended` is set/cleared by the platform super-admin console
 * (App\Services\Admin\SuperAdminService); `active` is the default a tenant is created in.
 *
 * The Tenant model does not cast this column (stancl's virtual-column machinery makes casts fragile),
 * so the service compares/writes via ->value against a plain string $tenant->status.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
