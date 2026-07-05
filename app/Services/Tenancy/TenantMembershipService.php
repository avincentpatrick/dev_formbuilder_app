<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantUserStatus;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The tenant-membership lifecycle (multi-tenancy-rbac-design.md §7): invite → accept / decline, plus
 * remove and ownership-transfer. Every method assumes the caller has already established the DB tenant
 * context (app.current_tenant_id + Spatie's permissions team) for the tenant it operates on — the
 * EstablishTenantDatabaseContext middleware does this on the subdomain, and the RLS policies are the
 * backstop if a caller gets it wrong (a mismatched context fails closed rather than leaking).
 *
 * The side effects Spatie has no awareness of on its own — materializing/removing the tenant-scoped
 * model_has_roles row and revoking the user's tenant-scoped tokens — are owned here, each wrapped in a
 * single transaction (§7's "one atomic operation" requirement).
 */
final class TenantMembershipService
{
    private const INVITE_TTL_DAYS = 7;

    /**
     * Invite a person (by email) to a tenant with a reserved role. Creates a placeholder user if the
     * email is unknown, or reuses the existing global identity — resolved on the pre-auth `pgsql_auth`
     * connection because the join-shape `users` RLS hides a non-member from the inviting Admin (the same
     * cross-RLS lookup CreateNewUser uses for email uniqueness). Reactivates a prior declined/removed
     * membership row rather than duplicating it (the (tenant_id, user_id) unique constraint).
     */
    public function invite(Tenant $tenant, string $email, string $roleName, User $invitedBy): TenantUser
    {
        if ($roleName === 'owner') {
            throw MembershipException::cannotInviteAsOwner();
        }

        $role = Role::query()->where('name', $roleName)->whereNull('tenant_id')->first();
        if ($role === null) {
            throw MembershipException::unknownRole($roleName);
        }

        $email = Str::lower($email);
        $plainToken = Str::random(48);

        // Placeholder-user creation + the invite row are one unit: a failed invite must not orphan a
        // freshly-created placeholder. The notification is sent only after the row commits.
        [$invite, $user] = DB::transaction(function () use ($email, $role, $invitedBy, $plainToken): array {
            $user = $this->resolveOrCreateUser($email);

            // RLS + BelongsToTenant scope this to the current tenant, so this is "their membership here".
            $existing = TenantUser::query()->where('user_id', $user->id)->first();
            if ($existing !== null && $existing->status === TenantUserStatus::Active) {
                throw MembershipException::alreadyMember($email);
            }

            $invite = $existing ?? new TenantUser;
            $invite->fill([
                'user_id' => $user->id,
                'status' => TenantUserStatus::Invited,
                'invited_role_id' => $role->id,
                'invited_by' => $invitedBy->id,
                'invited_at' => now(),
                'invite_expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
                'invite_token' => hash('sha256', $plainToken), // opaque + hashed at rest (§7)
                'joined_at' => null,
                'removed_at' => null,
                'removed_by' => null,
            ])->save(); // BelongsToTenant fills tenant_id on create

            return [$invite, $user];
        });

        $user->notify(new TenantInvitationNotification($tenant, $plainToken));

        return $invite;
    }

    /**
     * Accept an invitation as $user: activate the membership and — only now — materialize the reserved
     * role into a real model_has_roles row (§7: an unaccepted invite grants nothing). syncRoles keeps
     * the "one role per tenant" invariant; it and the RLS INSERT both key on the active tenant.
     */
    public function accept(TenantUser $invite, User $user): TenantUser
    {
        $this->assertPending($invite);
        if ($invite->user_id !== $user->id) {
            throw MembershipException::invitationMismatch();
        }

        return DB::transaction(function () use ($invite, $user): TenantUser {
            $invite->fill([
                'status' => TenantUserStatus::Active,
                'joined_at' => now(),
                'invite_token' => null, // consume the token
            ])->save();

            $role = Role::query()->whereKey($invite->invited_role_id)->first();
            if ($role !== null) {
                $user->syncRoles([$role]);
            }

            return $invite;
        });
    }

    /** Decline a pending invitation — no model_has_roles row is ever created (§7). */
    public function decline(TenantUser $invite): TenantUser
    {
        $this->assertPending($invite);

        $invite->fill([
            'status' => TenantUserStatus::Declined,
            'invite_token' => null,
        ])->save();

        return $invite;
    }

    /**
     * Remove an active member — one atomic transaction (§7): mark the membership removed, delete the
     * tenant-scoped role assignment, and revoke every tenant-scoped Sanctum token for the user. The
     * Owner cannot be removed (transfer ownership first).
     */
    public function remove(Tenant $tenant, User $member, User $actor): void
    {
        $membership = TenantUser::query()->where('user_id', $member->id)->first();
        if ($membership === null || $membership->status !== TenantUserStatus::Active) {
            throw MembershipException::notAMember();
        }
        if ($tenant->owner_user_id === $member->id) {
            throw MembershipException::cannotRemoveOwner();
        }

        DB::transaction(function () use ($tenant, $member, $actor, $membership): void {
            $membership->fill([
                'status' => TenantUserStatus::Removed,
                'removed_at' => now(),
                'removed_by' => $actor->id,
            ])->save();

            $member->syncRoles([]); // detach the tenant-scoped role (Spatie team = current tenant)

            DB::table('personal_access_tokens')
                ->where('tokenable_type', $member->getMorphClass())
                ->where('tokenable_id', $member->id)
                ->where('tenant_id', $tenant->id)
                ->delete(); // strict RLS scopes this to the current tenant
        });
    }

    /**
     * Transfer ownership to another active member — one atomic transaction (§7): repoint
     * tenants.owner_user_id, grant the incoming member Owner, and demote the outgoing Owner to Admin
     * (never left roleless). The Owner uniqueness is enforced here at the app layer, not by a DB
     * constraint (mirroring §3's stated approach).
     */
    public function transferOwnership(Tenant $tenant, User $newOwner, User $actor): void
    {
        $membership = TenantUser::query()->where('user_id', $newOwner->id)->first();
        if ($membership === null || $membership->status !== TenantUserStatus::Active) {
            throw MembershipException::ownershipTargetNotMember();
        }

        $currentOwnerId = $tenant->owner_user_id;
        if ($currentOwnerId === $newOwner->id) {
            throw MembershipException::alreadyOwner();
        }

        DB::transaction(function () use ($tenant, $newOwner, $currentOwnerId): void {
            // tenants is the central, RLS-exempt table — a plain update.
            $tenant->forceFill(['owner_user_id' => $newOwner->id])->save();

            $newOwner->syncRoles(['owner']);

            if ($currentOwnerId !== null) {
                // The outgoing Owner is by definition an active member ⇒ visible under the current
                // tenant's `users` RLS policy, so a plain default-connection lookup resolves them.
                User::find($currentOwnerId)?->syncRoles(['admin']);
            }

            // TODO(audits, Phase 1): emit audits event='permission_changed' once the audits table lands.
        });
    }

    private function assertPending(TenantUser $invite): void
    {
        if ($invite->status !== TenantUserStatus::Invited) {
            throw MembershipException::notPending();
        }
        if ($invite->invite_expires_at !== null && $invite->invite_expires_at->isPast()) {
            throw MembershipException::expired();
        }
    }

    /** Resolve an existing global identity by email (cross-RLS via pgsql_auth), or create a placeholder. */
    private function resolveOrCreateUser(string $email): User
    {
        $existing = $this->resolveUserByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make(Str::random(48)), // unusable until they set one on accept
        ]);
    }

    private function resolveUserByEmail(string $email): ?User
    {
        $user = User::on('pgsql_auth')->where('email', $email)->first();
        $user?->setConnection((string) config('database.default'));

        return $user;
    }
}
