<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tenant membership lifecycle state (multi-tenancy-rbac-design.md §7). Stored as varchar; the invite
 * → active → (suspended|declined|removed) transitions and their side effects are implemented in
 * Increment B2. The `active` value is the one the `users` visibility RLS policy keys on.
 */
enum TenantUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Declined = 'declined';
    case Removed = 'removed';
}
