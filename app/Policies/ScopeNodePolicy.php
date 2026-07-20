<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ScopeNode;
use App\Models\User;

/**
 * Authorization for the tenant's scoping hierarchy (Increment G10a, multi-tenancy-rbac-design.md §5).
 *
 * Every method is the same single permission check, and that is the point: `scopes.manage` is the 28th
 * permission, granted to Owner and Admin only. Authoring the hierarchy IS authoring the authorization
 * structure — a node is what a grant can be made against — so it carries the same restriction as
 * `forms.collaborators.manage`, and for the identical anti-escalation reason recorded at RBAC §5:111.
 *
 * There is deliberately no per-node product rule here (who may edit which branch). That is a product
 * decision belonging with the UI that surfaces it, and would be unreachable code in G10a, which ships no
 * routes. G10b owns it — along with `ResourceGrantPolicy` and its self-grant/anti-amplification rules.
 *
 * Permission checks go through `$user->can()` rather than `hasPermissionTo()`, matching {@see FormPolicy}:
 * the latter THROWS PermissionDoesNotExist when the catalog is not seeded, and policies are reachable
 * off-tenant where no catalog or team is set.
 */
final class ScopeNodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('scopes.manage');
    }

    public function view(User $user, ScopeNode $node): bool
    {
        return $user->can('scopes.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('scopes.manage');
    }

    public function update(User $user, ScopeNode $node): bool
    {
        return $user->can('scopes.manage');
    }

    public function delete(User $user, ScopeNode $node): bool
    {
        return $user->can('scopes.manage');
    }
}
