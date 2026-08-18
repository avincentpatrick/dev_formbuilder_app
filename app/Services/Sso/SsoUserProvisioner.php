<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\TenantUserStatus;
use App\Models\Role;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Authorization\AssignableRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Turns a validated {@see SsoIdentity} into a member of this workspace (P1b — ADR-0016 §D7).
 *
 * ── ⚠️ THE DEFAULT CONNECTION, NOT `pgsql_privileged` — AND THE FIRST PLAN HAD THIS WRONG ────────────
 * The reasoning that reached for an elevated connection was "`users` is FORCE RLS, so an insert with no
 * user context must be refused". The premise is real and the conclusion is not: `users_app_insert` is
 * `WITH CHECK (true)`, precisely so registration and invite-placeholder creation — which also run with no
 * authenticated user — can write. {@see CreateNewUser} and
 * {@see TenantMembershipService::resolveOrCreateUser()} are the live precedents. Reaching for
 * `pgsql_privileged` here would have been a permanent privilege escalation of the SSO path, bought to solve
 * a problem that does not exist.
 *
 * Resolution goes the other way: an EXISTING identity is found on `pgsql_auth`, where the join-shape
 * visibility policy is OR'd away and a person who is not yet a member of this tenant is therefore visible.
 * {@see TenantMembershipService::resolveUserByEmail()} owns that hop, including putting the model back on
 * the default connection before anything writes through it — and the exact-email rule that keeps a
 * cross-tenant read from becoming a cross-tenant directory.
 *
 * ── THE FOUR MEMBERSHIP OUTCOMES ────────────────────────────────────────────────────────────────────
 *   · Active     → in, with no write at all. The common case, and the one that must stay cheap.
 *   · Suspended  → REFUSED. An explicit administrative sanction, and an SSO sign-in that silently reversed
 *                  it would make the sanction unenforceable in exactly the workspaces most likely to rely
 *                  on it. This is the one status {@see TenantMembershipService::joinViaSso()} would happily
 *                  reactivate, which is why the check lives here, before the call.
 *   · Invited    → activated AT THE INVITED ROLE, not at `default_role_name`. An admin who invited someone
 *                  as an Admin expressed an intent about that person; letting the directory's default
 *                  silently demote them would make the invitation surface untrustworthy.
 *   · absent, Declined or Removed → JIT territory. Gated on `jit_provisioning_enabled` and landed at
 *                  `default_role_name`, which the database CHECK constrains to the seeded catalog MINUS
 *                  `owner` ({@see AssignableRoles}) — because RBAC §5 establishes Owner only by ownership
 *                  transfer and an IdP attribute must never be a path to it.
 *
 * ── ONE TRANSACTION AROUND BOTH WRITES, AND IT IS NOT COSMETIC ──────────────────────────────────────
 * `joinViaSso()` returns NULL rather than throwing when the seat quota is full — correct for the
 * self-registration door it shares, where the caller already has a committed account and being left
 * without a workspace is a state the product has. Here it is not correct: a session established with no
 * membership sees an empty workspace through RLS and reads as data loss. So the refusal is raised as an
 * exception, and the enclosing transaction is what stops a freshly created user being orphaned by it.
 */
final class SsoUserProvisioner
{
    public function __construct(private readonly TenantMembershipService $memberships) {}

    /**
     * @throws SsoAuthenticationException
     */
    public function provision(Tenant $tenant, SsoConnection $connection, SsoIdentity $identity): User
    {
        return DB::transaction(function () use ($tenant, $connection, $identity): User {
            $user = $this->memberships->resolveUserByEmail($identity->email);
            $membership = $user === null ? null : $this->membershipFor($user);

            if ($membership?->status === TenantUserStatus::Active) {
                return $user;
            }

            if ($membership?->status === TenantUserStatus::Suspended) {
                throw SsoAuthenticationException::membershipSuspended($identity->email);
            }

            // ⚠️ JIT MAY CREATE AN ACCOUNT; IT MAY NEVER ADOPT ONE. `resolveUserByEmail()` runs on
            // `pgsql_auth` and sees every account in the deployment, and nothing requires that the address
            // an IdP asserts belongs to a domain this workspace controls — so without this line an admin of
            // any SSO-entitled workspace could assert a stranger's address and be signed in as them. See
            // SsoAuthenticationException::existingAccountNotMember() for the full chain and for why the
            // refusal is this narrow: a membership row of ANY status means this workspace has already made a
            // decision about that person, and a brand-new address is unaffected.
            if ($user !== null && $membership === null) {
                throw SsoAuthenticationException::existingAccountNotMember($identity->email);
            }

            $roleName = $this->roleFor($connection, $membership);

            // The JIT gate covers everything except completing an invitation: an invited person was
            // authorized by an admin by name, which is a stronger statement than the toggle makes.
            if ($membership?->status !== TenantUserStatus::Invited && ! $connection->jit_provisioning_enabled) {
                throw SsoAuthenticationException::provisioningDisabled($identity->email);
            }

            $user ??= $this->createUser($identity);

            if ($this->memberships->joinViaSso($tenant, $user, $roleName) === null) {
                throw SsoAuthenticationException::seatQuotaExhausted($identity->email);
            }

            return $user;
        });
    }

    /** This tenant's membership row for the user. RLS is the scoping — see `SsoConnectionService`. */
    private function membershipFor(User $user): ?TenantUser
    {
        return TenantUser::query()->where('user_id', (string) $user->getKey())->first();
    }

    /**
     * The invited role when one is pending, the connection's default otherwise.
     *
     * Falls back to the default if the invited role has since been deleted from the catalog: a dangling
     * `invited_role_id` must not strand a legitimate sign-in, and `joinViaSso()` would throw
     * `unknownRole` on a name it cannot resolve.
     */
    private function roleFor(SsoConnection $connection, ?TenantUser $membership): string
    {
        if ($membership?->status !== TenantUserStatus::Invited || $membership->invited_role_id === null) {
            return $connection->default_role_name;
        }

        $invited = Role::query()->whereKey($membership->invited_role_id)->value('name');

        return is_string($invited) && $invited !== '' ? $invited : $connection->default_role_name;
    }

    /**
     * A brand-new account for someone the identity provider has vouched for.
     *
     * ⚠️ `email_verified_at` IS STAMPED, AND THAT IS THE IdP's CLAIM RATHER THAN A CONVENIENCE. The
     * assertion is signed by the tenant's own trust anchor and names this address; there is no verification
     * email that could tell us more than that. It matters beyond tidiness: `MustVerifyEmail` is implemented
     * on this model, and an SSO user who could never complete a verification round trip would be locked
     * out the moment the `verified` middleware guards anything.
     *
     * ⚠️ NO ToS OR PRIVACY STAMP. Both columns stay null. Nothing gates on them today, and recording an
     * acceptance that never happened is a worse default than leaving the absence visible — the placeholder
     * user an invitation creates is in exactly the same state until the person accepts in their own
     * browser.
     *
     * The password is a random hash rather than a null: the column is NOT NULL, and nobody — including this
     * process, which discards the string immediately — can present it. Password reset remains available as
     * the escape hatch if a tenant ever turns SSO off, which is why the account is a real one rather than a
     * special case.
     */
    private function createUser(SsoIdentity $identity): User
    {
        // ⚠️ ONE INSERT, NEVER A CREATE FOLLOWED BY AN UPDATE — AND THE DIFFERENCE IS SILENT. `users` has a
        // permissive INSERT policy (`WITH CHECK (true)`) but an OWN-ROW update policy keyed on
        // `app.current_user_id`, and this runs before `Auth::login()`, so that GUC is still NULL. A
        // follow-up `save()` would match no policy, update ZERO rows, throw nothing, and leave the account
        // unverified — which the `verified` middleware then turns into a lockout with no error to trace.
        // `forceFill` on a new model is what carries the non-fillable column into the INSERT itself.
        $user = new User;
        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
            // Random and immediately discarded: the column is NOT NULL and nobody, including this process,
            // can present it. Password reset stays available as the escape hatch if a tenant turns SSO off.
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
