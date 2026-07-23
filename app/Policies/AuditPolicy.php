<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Audit;
use App\Models\User;

/**
 * Read authorization for the audit log (H4, audit-compliance-logging-spec.md §3,
 * multi-tenancy-rbac-design.md §5). The ledger is visible to Owner and Admin only — the `audit_log.view`
 * permission the RBAC matrix already grants exactly those two roles. Viewer is deliberately excluded (§3,
 * RBAC §3:45). There is no create/update/delete: audit rows are written only by {@see
 * \App\Support\Audit\AuditLogger} and are immutable, so no policy method could authorize a mutation the
 * append-only RLS shape denies at the database anyway.
 *
 * Uses `$user->can()` rather than `hasPermissionTo()`, matching {@see ScopeNodePolicy}: the latter throws
 * when the catalog is unseeded, and policies are reachable off-tenant.
 */
final class AuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit_log.view');
    }

    public function view(User $user, Audit $audit): bool
    {
        return $user->can('audit_log.view');
    }
}
