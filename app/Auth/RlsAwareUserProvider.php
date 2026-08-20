<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves users for authentication on a dedicated pre-auth DB connection (`pgsql_auth`, the
 * least-privilege `meridian_auth` role), solving the RLS-on-`users` vs. login bootstrapping problem
 * (see App\Support\Tenancy\TenantIsolation::usersVisibility / the apply_users_rls migration).
 *
 * The join-shape `users` SELECT policy fails closed when neither a user context nor an active tenant
 * membership is set — which is exactly the state during login, registration email-uniqueness, and
 * password reset. As the app role (`meridian_app`) those lookups would return zero rows. So every
 * provider query runs on `pgsql_auth` (via createModel below), which has a permissive `TO meridian_auth`
 * policy scoped to the `users` table. ⚠️ It is no longer *only* `users`: M8's `2026_08_17_000107`
 * adds a read-only, role-scoped `SELECT ON tenant_users` for the invitation identity check. This
 * provider itself touches nothing but `users`; the bound applying to the connection as a whole is
 * multi-tenancy-rbac-design.md §9's rule that no user-supplied predicate may run on it.
 *
 * Connection handoff — the important subtlety:
 *   - retrieveById / retrieveByToken rehydrate the AUTHENTICATED user on each request; their result is
 *     reset to the default connection (`meridian_app`) so all subsequent application queries run under
 *     the join-shape policy with no elevated connection leaking into request code.
 *   - retrieveByCredentials is used during the login CHECK and, crucially, the password-RESET save
 *     (unauthenticated, so the own-row `meridian_app` UPDATE policy would block the write). Its result
 *     stays on `pgsql_auth`, whose permissive UPDATE policy lets the reset succeed.
 */
class RlsAwareUserProvider extends EloquentUserProvider
{
    private const AUTH_CONNECTION = 'pgsql_auth';

    /**
     * Bind every provider query to the pre-auth connection. All of the parent's retrieve* methods
     * build their query from createModel()/newModelQuery(), so this is the single routing point.
     */
    public function createModel()
    {
        $model = parent::createModel();
        $model->setConnection(self::AUTH_CONNECTION);

        return $model;
    }

    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);
        $user?->setConnection($this->defaultConnection());

        return $user;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = parent::retrieveByToken($identifier, $token);
        $user?->setConnection($this->defaultConnection());

        return $user;
    }

    // retrieveByCredentials() is intentionally NOT overridden: it inherits createModel() (so the lookup
    // runs on pgsql_auth) and its result is left on that connection for the password-reset save path.

    public function updateRememberToken(Authenticatable $user, $token)
    {
        // $user is always an Eloquent model here (EloquentUserProvider). Write the remember token on
        // the auth connection (permissive UPDATE for meridian_auth), then restore the connection.
        $original = $user->getConnectionName();
        $user->setConnection(self::AUTH_CONNECTION);
        parent::updateRememberToken($user, $token);
        $user->setConnection($original ?? $this->defaultConnection());
    }

    private function defaultConnection(): string
    {
        return (string) config('database.default');
    }
}
