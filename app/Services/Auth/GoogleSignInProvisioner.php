<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\RlsAwareUserProvider;
use App\Enums\TenantUserStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Settings\RegistrationGate;
use App\Services\Sso\SsoUserProvisioner;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Auth\GoogleIdentity;
use App\Support\Auth\GoogleSignInOutcome;
use App\Support\Auth\GoogleSignInRefusedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Turns a verified {@see GoogleIdentity} into somebody this product can sign in (J3c2 — ADR-0017 §D4/§D8).
 *
 * {@see SsoUserProvisioner}'s sibling, and the same connection reasoning applies: writes go on the DEFAULT
 * connection (`users_app_insert` is `WITH CHECK (true)`, precisely so registration and invite-placeholder
 * creation can write with no authenticated user), while an EXISTING identity is RESOLVED on `pgsql_auth`,
 * where `users_auth_select` is `USING (true)` and a person who is not yet a member of this workspace is
 * therefore visible at all. Reaching for `pgsql_privileged` here would be a permanent privilege escalation
 * bought to solve a problem that does not exist.
 *
 * ── THE LINKAGE TREE (§D4), IN THE ORDER IT IS ASKED ────────────────────────────────────────────────
 *   · `email_verified !== true`                        → REFUSED. This flow's signature check, asserted a
 *     second time here because the callback and this class are separated by a host and a stored row.
 *   · `users.google_id = sub`                          → that is the person. The address is not consulted
 *     at all, and {@see users.email} is NEVER rewritten from Google (§D5).
 *   · email hit, `google_id` = a DIFFERENT sub         → REFUSED. The reassigned-address takeover.
 *   · email hit, `google_id` NULL, local UNVERIFIED    → REFUSED (decision of record, 2026-08-14).
 *   · email hit, `google_id` NULL, local verified      → LINK, and see the warning on {@see link()}.
 *   · no match                                         → CREATE, one INSERT, gated on `RegistrationGate`.
 *
 * ── THE MEMBERSHIP OUTCOMES ARE ADR-0016 §D20, REUSED RATHER THAN RESTATED ──────────────────────────
 * Active → in, no write. Suspended → REFUSED before the call, because
 * {@see TenantMembershipService::joinViaGoogle()} would happily reactivate it. Invited → activated AT THE
 * INVITED ROLE (see {@see roleFor()}). Absent/Declined/Removed → gated on `RegistrationGate`, which is the
 * same answer the login page uses to decide whether to show the button at all. A full workspace REFUSES.
 *
 * ── ⚠️⚠️ ONE TRANSACTION, AND THE LINK MUST HAPPEN BEFORE IT OPENS — THIS IS A DEADLOCK, NOT A STYLE ──
 * Everything on the default connection is wrapped, so a seat-quota refusal cannot leave a freshly created
 * account orphaned — {@see SsoUserProvisioner}'s argument, for the same reason: a session with no
 * membership sees an empty workspace through RLS and reads as data loss.
 *
 * The `google_id` link, however, runs on `pgsql_auth` — a SEPARATE SESSION that is not part of that
 * transaction. The first version of this class performed it LAST, inside the transaction, reasoning that
 * a rollback could then never strand a link. **That version hangs forever**, and the reason is a lock
 * interaction neither half is obviously responsible for:
 *
 *   · `users.google_id` is UNIQUE. PostgreSQL's cheap `FOR NO KEY UPDATE` applies only when no
 *     unique-indexed column changes, so this UPDATE takes a full **`FOR UPDATE`** row lock.
 *   · `attachMember()`'s INSERT into `tenant_users` takes **`FOR KEY SHARE`** on the parent `users` row,
 *     because `tenant_users_user_id_foreign` references it.
 *   · Those two conflict — and the transaction holding the `FOR KEY SHARE` cannot commit until this
 *     method returns. **The request blocks on itself**, with no error, no rollback and no timeout.
 *
 * Measured, not reasoned: a test run sat for over three hours with
 * `pg_blocking_pids()` naming the application's own connection as the blocker.
 *
 * So resolution AND linkage run first, in {@see resolveAndLink()}, before any transaction exists. That
 * ordering is also the honest model — linkage is an ACCOUNT fact, membership is a WORKSPACE fact — and
 * its cost is recorded as residual 15 in `docs/security-threat-model.md` §9: a workspace-level refusal
 * after a successful link leaves the account linked. Every §D4 condition was met, so that is correct on
 * its own terms, and the next attempt resolves by `sub`.
 */
final class GoogleSignInProvisioner
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly RegistrationGate $registration,
    ) {}

    /**
     * @param  ?Tenant  $tenant  null is the CENTRAL arm — an account with no workspace, exactly what
     *                           central-host registration produces today (§D12).
     *
     * @throws GoogleSignInRefusedException
     */
    public function provision(?Tenant $tenant, GoogleIdentity $identity, Request $request): GoogleSignInOutcome
    {
        // §D3's second assertion. The first is at the callback, before anything is stored.
        if (! $identity->emailVerified) {
            throw GoogleSignInRefusedException::emailNotVerified();
        }

        // ⚠️⚠️ RESOLUTION AND LINKAGE HAPPEN **BEFORE** THE TRANSACTION OPENS, AND THAT IS A DEADLOCK FIX
        // RATHER THAN A TIDY-UP. See {@see link()} for the full mechanism; the short version is that
        // `users.google_id` is UNIQUE, so updating it needs a `FOR UPDATE` row lock, while inserting the
        // `tenant_users` row inside the transaction takes `FOR KEY SHARE` on the SAME `users` row for its
        // foreign key. Those two conflict. Because the link runs on `pgsql_auth` — a separate session that
        // is NOT part of this transaction — it would wait for a transaction that cannot commit until it
        // returns. One request blocking on itself, forever, with no error and no rollback.
        //
        // Ordering it first also happens to be the honest model: linkage is an ACCOUNT fact and membership
        // is a WORKSPACE fact, and the account question is fully answerable before the workspace one is
        // asked. The accepted cost is recorded as residual 15 in `docs/security-threat-model.md` §9: a
        // later workspace-level refusal (suspended, closed, quota) leaves the account linked. That is
        // correct on its own terms — every §D4 condition was met — and the next attempt resolves by `sub`.
        $resolved = $this->resolveAndLink($identity);

        return DB::transaction(function () use ($tenant, $identity, $request, $resolved): GoogleSignInOutcome {
            $user = $resolved;

            $membership = $user !== null && $tenant !== null ? $this->membershipFor($user) : null;

            if ($membership?->status === TenantUserStatus::Suspended) {
                throw GoogleSignInRefusedException::membershipSuspended();
            }

            // The gate covers a new ACCOUNT and a new MEMBERSHIP. Completing an invitation is exempt: an
            // admin authorized that person by name, which is a stronger statement than any toggle makes —
            // the `jit_provisioning_enabled` carve-out `SsoUserProvisioner` draws, against our gate.
            $isNewMembership = $tenant !== null
                && $membership?->status !== TenantUserStatus::Active
                && $membership?->status !== TenantUserStatus::Invited;

            if (($user === null || $isNewMembership) && ! $this->registration->allows($request)) {
                throw GoogleSignInRefusedException::registrationClosed();
            }

            $created = false;

            if ($user === null) {
                $user = $this->createUser($identity);
                $created = true;
            }

            // Active membership: in, with no write at all. The common case, and the one that stays cheap.
            if ($tenant !== null && $membership?->status !== TenantUserStatus::Active) {
                if ($this->memberships->joinViaGoogle($tenant, $user, $this->roleFor($membership)) === null) {
                    throw GoogleSignInRefusedException::seatQuotaExhausted();
                }
            }

            return new GoogleSignInOutcome($user, $created);
        });
    }

    /**
     * Answer "who is this?" entirely, including writing the link — all OUTSIDE any transaction.
     *
     * Returns null when nobody local matches, which is the caller's signal to create.
     *
     * @throws GoogleSignInRefusedException
     */
    private function resolveAndLink(GoogleIdentity $identity): ?User
    {
        $user = $this->resolveBySubject($identity->subject);

        if ($user !== null) {
            return $user;
        }

        $user = $this->memberships->resolveUserByEmail($identity->email);

        if ($user === null) {
            return null;
        }

        // Resolution by subject already failed, so a non-null `google_id` here is necessarily a DIFFERENT
        // one — §D2's reassigned address, arriving as somebody else's account.
        if ($user->google_id !== null) {
            throw GoogleSignInRefusedException::subjectMismatch();
        }

        if ($user->email_verified_at === null) {
            throw GoogleSignInRefusedException::localAccountUnverified();
        }

        $this->link($user, $identity);

        return $user;
    }

    /**
     * The account already bound to this Google `sub`, or null.
     *
     * ⚠️ ON `pgsql_auth`, FOR {@see TenantMembershipService::resolveUserByEmail()}'s REASON. As
     * `meridian_app` with no user context the fail-closed join-shape policy on `users` HIDES a person who
     * is not an active co-tenant — which is most of the people this flow exists to sign in — so the lookup
     * would return null and the flow would try to CREATE a second account on an address the unique index
     * then refuses with a raw 500.
     *
     * ⚠️ AND THE SAME STANDING RULE APPLIES: no user-supplied predicate may run on this connection beyond
     * exact equality. `sub` is attacker-influenced only in the sense that it arrives from Google over a
     * verified exchange; it is compared with `=` and nothing else, and the model is put back on the default
     * connection before anything writes through it.
     */
    private function resolveBySubject(string $subject): ?User
    {
        $user = User::on('pgsql_auth')->where('google_id', $subject)->first();
        $user?->setConnection((string) config('database.default'));

        return $user;
    }

    /**
     * Bind this Google account to an existing local one (§D4).
     *
     * ⚠️⚠️ ON `pgsql_auth`, AND THE AFFECTED-ROW COUNT IS ASSERTED. This is the third time this product has
     * met the shape: `users` carries an OWN-ROW update policy keyed on `app.current_user_id`, and nobody is
     * authenticated yet, so a write on the DEFAULT connection matches no policy, affects ZERO ROWS, throws
     * NOTHING, and returns as if it worked. On `pgsql_auth` the `users_auth_select` policy is `USING (true)`
     * so the row is visible to its own update, and the permissive `users_app_update` then applies. It is
     * the same asymmetry that let `/reset-password` work while `PUT /user/password` silently did nothing.
     *
     * ⚠️ AND IT IS A CONDITIONAL UPDATE, NOT A `save()`. `whereNull('google_id')` makes two concurrent
     * first-time sign-ins settle at the database rather than in PHP, and turns the zero-row failure above
     * from invisible into an exception. `SoftDeletes` adds `deleted_at IS NULL` for free, so a deleted
     * account cannot be linked onto either.
     *
     * There was no prior precedent in this codebase for an affected-count-checked write on this connection
     * — {@see RlsAwareUserProvider::updateRememberToken()} and {@see User::replaceRecoveryCode()}
     * are the only other `pgsql_auth` writes and neither checks. This is the first.
     *
     * ⚠️⚠️ AND IT MUST NEVER BE CALLED FROM INSIDE A TRANSACTION ON THE DEFAULT CONNECTION. Because
     * `google_id` is UNIQUE this UPDATE takes a full `FOR UPDATE` row lock, which conflicts with the
     * `FOR KEY SHARE` that a `tenant_users` INSERT takes on the same `users` row for its foreign key. On a
     * separate session it would then wait for a transaction that cannot commit until this call returns —
     * the request blocking on itself, with no error, no rollback and no timeout. See the class docblock;
     * this is why {@see resolveAndLink()} runs before {@see provision()} opens one.
     *
     * @throws GoogleSignInRefusedException
     */
    private function link(User $user, GoogleIdentity $identity): void
    {
        $affected = User::on('pgsql_auth')
            ->whereKey($user->getKey())
            ->whereNull('google_id')
            ->update(['google_id' => $identity->subject]);

        if ($affected !== 1) {
            throw GoogleSignInRefusedException::linkageNotWritten();
        }

        // Keep the in-memory model honest for anything downstream that reads it; the row is already written.
        $user->setAttribute('google_id', $identity->subject);
        $user->syncOriginalAttribute('google_id');
    }

    /** This workspace's membership row for the user. RLS is the scoping. */
    private function membershipFor(User $user): ?TenantUser
    {
        return TenantUser::query()->where('user_id', (string) $user->getKey())->first();
    }

    /**
     * The invited role when one is pending, `viewer` otherwise.
     *
     * ⚠️ THIS IS WHERE §D20's "Invited → activated at the INVITED role" LIVES, AND IT CANNOT LIVE IN
     * {@see TenantMembershipService::attachMember()}: that method overwrites `invited_role_id` with
     * whatever role it is handed, so the decision has to be made before the call. SSO makes it in exactly
     * the same place, against `sso_connections.default_role_name`.
     *
     * `viewer` for everyone else — the least-privileged role in the catalog, and
     * {@see TenantMembershipService::joinOpenTenant()}'s argument applies unchanged: someone who arrived
     * holding a consumer Google account has proved nothing about what they should be able to do. There is
     * deliberately no per-workspace default for this door; a workspace that wants to choose a role for
     * arrivals has the invitation surface, which is what expresses that intent by name.
     *
     * Falls back if the invited role has since been deleted from the catalog: a dangling `invited_role_id`
     * must not strand a legitimate sign-in, and `joinViaGoogle()` would throw `unknownRole` on a name it
     * cannot resolve.
     */
    private function roleFor(?TenantUser $membership): string
    {
        if ($membership?->status !== TenantUserStatus::Invited || $membership->invited_role_id === null) {
            return 'viewer';
        }

        $invited = Role::query()->whereKey($membership->invited_role_id)->value('name');

        return is_string($invited) && $invited !== '' ? $invited : 'viewer';
    }

    /**
     * A brand-new account for someone Google has vouched for.
     *
     * ⚠️ ONE INSERT, NEVER A CREATE FOLLOWED BY AN UPDATE — {@see SsoUserProvisioner::createUser()}'s
     * warning, unchanged and for the identical reason: this runs before `Auth::login()`, so a follow-up
     * `save()` would match no policy, update zero rows, throw nothing, and leave the account unverified —
     * which the `verified` middleware then turns into a lockout with no error to trace. `forceFill` on a
     * new model is what carries `google_id` and `email_verified_at` into the INSERT itself; neither is in
     * `User`'s `#[Fillable]` list.
     *
     * ⚠️ `email_verified_at` IS STAMPED BECAUSE GOOGLE SAID `email_verified: true` AND WE CHECKED IT
     * STRICTLY. That claim is the entire basis for this account existing at all; a verification email
     * asking the same question would be theatre.
     *
     * ⚠️ NO ToS OR PRIVACY STAMP. Both columns stay null — recording an acceptance that never happened is
     * a worse default than leaving the absence visible.
     */
    private function createUser(GoogleIdentity $identity): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
            'google_id' => $identity->subject,
            // Random and immediately discarded: the column is NOT NULL and nobody, including this process,
            // can present it. Password reset stays available as the escape hatch.
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
