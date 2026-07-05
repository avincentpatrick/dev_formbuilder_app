<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Meridian permission model — Spatie's Permission with a UUIDv7 primary key.
 *
 * Same UUIDv7 rationale as {@see Role}. Permissions are a global, code-controlled vocabulary
 * (a new permission requires a code change to actually enforce), seeded once by RolePermissionSeeder;
 * per-user direct permission grants (model_has_permissions) are unused in Phase 1 — every
 * authorization decision flows through roles for auditability (multi-tenancy-rbac-design.md §4).
 */
class Permission extends SpatiePermission
{
    use HasUuidv7;
}
