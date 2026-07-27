<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ConnectionSubscription;
use App\Models\User;
use App\Services\Connectors\ConnectionService;

/**
 * Authorization for a native-connector delivery rule (H15b). Every method is the same single permission check
 * as {@see ConnectionPolicy}: `integrations.manage` (Owner and Admin only). A rule routes a domain event into
 * the tenant's third-party workspace using a grant held under that same permission, so it cannot sensibly carry
 * a weaker one.
 *
 * WHY THIS EXISTS ONLY NOW. H15a needed no rule policy: `routes/api.php` nests every subscription under
 * `{connection}` and gates on the PARENT (`can:update,connection`), so the grant's policy covered both. H15b
 * gives a rule its own page and therefore its own flat routes (`/integrations/rules/{connectionSubscription}`),
 * and those cannot gate on a parent binding they do not have.
 *
 * The flat shape is deliberate rather than incidental: {@see ConnectionService::disconnect()}
 * SOFT-DELETES the connection while leaving its rules alive (merely paused), so a nested `{connection}` binding
 * would 404 precisely when a tenant is tidying up after a disconnect. For the same reason nothing here resolves
 * authorization THROUGH `$subscription->grant` — that relation is null exactly in the case the page must handle.
 *
 * Tenant scoping is not this policy's job and never was: `connection_subscriptions` carries strict RLS, so a
 * cross-tenant id cannot bind in the first place. Checks go through `$user->can()` (NOT `hasPermissionTo()`,
 * which throws on an unseeded catalog) — the {@see ConnectionPolicy} convention.
 */
final class ConnectionSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function view(User $user, ConnectionSubscription $subscription): bool
    {
        return $user->can('integrations.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function update(User $user, ConnectionSubscription $subscription): bool
    {
        return $user->can('integrations.manage');
    }

    public function delete(User $user, ConnectionSubscription $subscription): bool
    {
        return $user->can('integrations.manage');
    }
}
