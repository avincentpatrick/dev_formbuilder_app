<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Connection;
use App\Models\User;
use App\Support\Api\ApiAbilities;

/**
 * Authorization for native-connector OAuth grants (ADR-0009, H15a). Every method is the same single
 * permission check: `integrations.manage` (granted to Owner and Admin only). Holding a credential that lets
 * the platform act inside the tenant's third-party workspace is the most consequential integration
 * capability there is, so it carries at least the restriction the webhook surface does.
 *
 * A NEW permission rather than a reuse of `webhooks.manage`, for the reason {@see ApiAbilities}
 * records for `scopes.manage`: `manage:webhooks` tokens are already minted, and reusing the permission behind
 * them would retroactively hand every one of those tokens authority over OAuth grants nobody agreed to.
 *
 * Checks go through `$user->can()` (NOT `hasPermissionTo()`, which THROWS when the catalog is unseeded), the
 * {@see WebhookEndpointPolicy} convention: policies are reachable off-tenant. API routes ALSO carry
 * `ability:manage:integrations` (scoping the TOKEN) — this policy re-checks the acting user's real permission,
 * which is what makes the ability safe as a scope. The tenant web route that starts an OAuth flow carries only
 * this policy, since a session carries no token.
 */
final class ConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function view(User $user, Connection $connection): bool
    {
        return $user->can('integrations.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function update(User $user, Connection $connection): bool
    {
        return $user->can('integrations.manage');
    }

    public function delete(User $user, Connection $connection): bool
    {
        return $user->can('integrations.manage');
    }
}
