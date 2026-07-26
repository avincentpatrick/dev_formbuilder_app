<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;

/**
 * Authorization for webhook endpoints (H13a). Every method is the same single permission check:
 * `webhooks.manage` (in the 28-permission catalog, granted to Owner and Admin only). Managing a delivery
 * target — where a tenant's submission data is pushed to a third party — is an administrative capability, so
 * it carries the same restriction as the other integration-management surfaces.
 *
 * Checks go through `$user->can()` (NOT `hasPermissionTo()`, which THROWS when the catalog is unseeded), the
 * {@see ScopeNodePolicy} convention: policies are reachable off-tenant. The route ALSO carries
 * `ability:manage:webhooks` (scoping the TOKEN) — this policy re-checks the acting user's real permission.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('webhooks.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('webhooks.manage');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('webhooks.manage');
    }
}
