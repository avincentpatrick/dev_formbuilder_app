<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Meridian role model — Spatie's Role with a UUIDv7 primary key.
 *
 * Roles are externally addressable (the forward-referenced GET /api/v1/roles), so they follow the
 * project-wide UUIDv7 PK strategy rather than Spatie's stock auto-increment bigint, avoiding
 * sequential-ID enumeration (multi-tenancy-rbac-design.md §4). The fixed 5-role catalog is seeded
 * once as global rows (tenant_id IS NULL) by RolePermissionSeeder; the name is stored as plain data
 * (not a PHP enum) because Spatie's teams/relationship machinery is built around queryable model rows.
 *
 * HasUuidv7 (via HasUuids) dynamically reports keyType='string' / incrementing=false and fills the
 * client-generated id on create, overriding Spatie's inherited integer-key defaults.
 */
class Role extends SpatieRole
{
    use HasUuidv7;
}
