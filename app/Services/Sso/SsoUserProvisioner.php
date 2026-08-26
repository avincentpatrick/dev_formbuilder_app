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
 * ── ⚠️ THE FIRST QUESTION IS NOT A MEMBERSHIP QUESTION AT ALL (M18 — ADR-0016 §D34) ────────────────
 * Before any of the outcomes below is reached, the assertion's email domain must be one this workspace has
 * PROVEN it controls ({@see SsoDomainService::isVerifiedFor()}). Everything under this heading, and M1's and
 * M9's refusals with it, are membership-layer answers to a trust-layer question — each correct, none able to
 * establish that `acme.test`'s connection is entitled to speak for `acme.test` at all.
 *
 * The check sits AFTER the `Active` early return and BEFORE every other branch, and both halves of that
 * placement are load-bearing: after `Active`, so **an active membership is the grandfather** and no live
 * deployment loses a member on deploy; before the rest, so an unproven workspace cannot use the failures
 * panel as a cross-tenant account-existence oracle.
 * {@see SsoAuthenticationException::domainNotVerified()} carries the full chain and the enumeration that
 * makes "an active membership is enough" a checked claim rather than a hopeful one.
 *
 * ── THE MEMBERSHIP OUTCOMES ─────────────────────────────────────────────────────────────────────────
 * ⛔ **AMENDED BY M9, AND THE OLD VERSION OF THIS BLOCK WAS THE DEFECT RATHER THAN A DESCRIPTION OF IT.**
 * It said `Invited` is *"an admin who invited someone as an Admin expressed an intent about that person"* and
 * grouped `Declined`/`Removed` as ordinary JIT territory. **An invitation names an ADDRESS; it establishes
 * nothing about who is behind one** — which is what M8 proved on the invitation door, and what made every
 * row-exists status a takeover here. So the statuses now fork on IDENTITY first and status second:
 *
 *   · Active     → in, with no write at all. The common case, and the one that must stay cheap.
 *   · Suspended  → REFUSED. An explicit administrative sanction, and an SSO sign-in that silently reversed
 *                  it would make the sanction unenforceable in exactly the workspaces most likely to rely
 *                  on it. This is the one status {@see TenantMembershipService::joinViaSso()} would happily
 *                  reactivate, which is why the check lives here, before the call.
 *   · an EXISTING account with no row here            → REFUSED ({@see SsoAuthenticationException::existingAccountNotMember()}).
 *   · an EXISTING **established** identity, whatever  → REFUSED ({@see SsoAuthenticationException::establishedIdentityNotJoined()}).
 *     its `Invited` / `Declined` / `Removed` row says     They complete the invitation in their own browser,
 *                                                        where the password check and the second-factor
 *                                                        challenge actually run.
 *   · Invited, and a NEVER-USED placeholder           → activated AT THE INVITED ROLE, not at
 *                                                        `default_role_name`. That courtesy survives M9
 *                                                        because it is now only ever extended to an account
 *                                                        this workspace's own invitation brought into being.
 *   · absent, or Declined / Removed on an identity    → JIT territory. Gated on `jit_provisioning_enabled`
 *     that is not established                            and landed at `default_role_name`, which the
 *                                                        database CHECK constrains to the seeded catalog
 *                                                        MINUS `owner` ({@see AssignableRoles}) — because
 *                                                        RBAC §5 establishes Owner only by ownership
 *                                                        transfer and an IdP attribute must never be a path
 *                                                        to it.
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
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly SsoDomainService $domains,
    ) {}

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

            // ⚠️⚠️ THE TRUST-LAYER QUESTION, AND IT IS ASKED BEFORE EVERY MEMBERSHIP-LAYER ONE (M18 — §D34).
            // A connection is metadata this workspace installed for itself, so a valid signature establishes
            // that somebody authenticated at a provider THEY chose — never which addresses that provider may
            // speak for. Every refusal below stands in for this fact and none of them could state it.
            //
            // ⚠️ THE POSITION IS THE FIX FOR A SECOND DEFECT, NOT A STYLE CHOICE. Placed after the two guards
            // below, an admin who has proven nothing about a domain could still assert any address and read
            // back from their own failures panel whether it has an account anywhere in the deployment —
            // `existing_account_not_member` renders as "Address already has an account elsewhere" while
            // `jit_disabled` renders as "Nobody here matches that address". Asked first, the only thing an
            // unproven workspace learns is that it has not proven the domain, which is also the only thing
            // it can act on. §D19's uniform 404 was always intact; the panel was the surface that leaked.
            //
            // ⚠️ AND IT IS AFTER THE `Active` RETURN, WHICH IS THE WHOLE GRANDFATHERING STORY. An active
            // membership IS the grandfather, so no live deployment loses a member on deploy and no
            // per-connection mode column, backfill or public-mailbox exclusion list is needed. That holds
            // because of what the four writers of `Active` require — see
            // SsoAuthenticationException::domainNotVerified(), which enumerates them rather than asserting
            // it. What DOES stop is a new joiner at an unverified domain, which is the control working.
            if (! $this->domains->isVerifiedFor($identity->email)) {
                throw SsoAuthenticationException::domainNotVerified(
                    $identity->email,
                    SsoDomainService::domainOf($identity->email) ?? '(none)',
                );
            }

            // ⚠️ JIT MAY CREATE AN ACCOUNT; IT MAY NEVER ADOPT ONE. `resolveUserByEmail()` runs on
            // `pgsql_auth` and sees every account in the deployment, and nothing requires that the address
            // an IdP asserts belongs to a domain this workspace controls — so without this line an admin of
            // any SSO-entitled workspace could assert a stranger's address and be signed in as them. See
            // SsoAuthenticationException::existingAccountNotMember() for the full chain. ⛔ M9 DELETED THE
            // SENTENCE THAT USED TO CLOSE THIS COMMENT — "a membership row of ANY status means this
            // workspace has already made a decision about that person" — because a row is a decision about
            // an ADDRESS. This line still fires only on "no row here at all"; the guard below asks the rest,
            // and a brand-new address is unaffected by either.
            // ⚠️ THE TWO REFUSALS ARE NESTED, NOT SEQUENTIAL, AND THAT IS DELIBERATE. They share one
            // premise — an account for this address ALREADY EXISTS — and stating it once makes the second
            // condition legible: past the first throw, a non-null `$user` necessarily has a membership row,
            // so `$membership` is a `TenantUser` here rather than something re-checked. Written flat, the
            // second guard needed a `$membership !== null` that PHPStan correctly reports as always true,
            // and a reader would have had to reconstruct why.
            if ($user !== null) {
                if ($membership === null) {
                    throw SsoAuthenticationException::existingAccountNotMember($identity->email);
                }

                // ⚠️ AND THE SAME QUESTION AGAIN FOR THE ROWS THAT USED TO DISARM THE LINE ABOVE (M9).
                // Reaching here means `Invited`, `Declined` or `Removed` — `Active` returned and
                // `Suspended` threw — and every one of them was adoptable, which made "invite a stranger,
                // then assert their address at your own IdP" a takeover needing no emailed token.
                // `identityIsEstablished()` is M8's predicate, reused rather than re-derived: the membership
                // row is the invitation it excludes, and its own `joined_at`/`removed_at` are what make a
                // REMOVED former member refuse. A never-used placeholder still completes its invitation
                // here, which is the whole blast radius.
                if ($this->memberships->identityIsEstablished($user, $membership)) {
                    throw SsoAuthenticationException::establishedIdentityNotJoined($identity->email);
                }
            }

            $roleName = $this->roleFor($connection, $membership);

            // The JIT gate covers everything except completing an invitation. ⚠️ M9 NARROWED WHAT THAT
            // SENTENCE CAN MEAN: an admin naming an ADDRESS is not a statement about the person behind it,
            // so the exemption is only reachable now for a never-used placeholder — the guard above has
            // already refused every established identity. Read on its own this line still looks like a
            // status check; it is load-bearing only because of what precedes it.
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
     * ⛔ **AND THAT PARAGRAPH WAS LOAD-BEARING FOR A DEFECT UNTIL M18, WHICH IS WHY IT IS AMENDED RATHER
     * THAN LEFT TO READ AS SETTLED.** *"Signed by the tenant's own trust anchor"* is a statement about the
     * signature, not about the address — and `users` is a DEPLOYMENT-WIDE table, so this line writes a
     * platform-global identity fact from a per-workspace trust root. For an address in a domain the
     * workspace did not control, the stamp was simply untrue, and it did not stay local:
     * `TenantMembershipService::identityIsEstablished()` reads this exact column, so a forged stamp fed M8's
     * own authentication predicate and denied the address's real owner the password-setting arm of their
     * later, genuine invitation. The sentence is true again — and only because the domain check now runs
     * before anything reaches here. **It is a conclusion that depends on a guard elsewhere, so it is stated
     * as one.**
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
