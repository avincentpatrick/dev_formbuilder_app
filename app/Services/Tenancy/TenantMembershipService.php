<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\AuditEvent;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Events\MemberInvited;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Entitlements\QuotaGuard;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\BrandPalette;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QuotaGuard $quota,
    ) {}

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
        // freshly-created placeholder.
        $invite = DB::transaction(function () use ($email, $role, $invitedBy, $plainToken): TenantUser {
            $user = $this->resolveOrCreateUser($email);

            // RLS + BelongsToTenant scope this to the current tenant, so this is "their membership here".
            $existing = TenantUser::query()->where('user_id', $user->id)->first();
            if ($existing !== null && $existing->status === TenantUserStatus::Active) {
                throw MembershipException::alreadyMember($email);
            }

            // Hard-block the active_seats quota (H5b / ADR-0008 §D4), reserve-on-invite: the gauge counts
            // Active + pending Invited (matching listMembers()), so only a GENUINELY NEW occupant consumes a
            // seat. Re-sending to someone already Invited is count-neutral (their row is already counted);
            // reactivating a Declined/Removed/Suspended row is a new seat. Inside the transaction so a
            // refusal rolls back before a placeholder user is orphaned.
            if ($existing === null || $existing->status !== TenantUserStatus::Invited) {
                $this->quota->assertCanCreate(UsageMetric::ActiveSeats);
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

            return $invite;
        });

        // Queued on the `mail` queue (H3). The accept URL is built HERE — in-request, under live tenant
        // context — so the queued notification carries only scalars (§D5) and needs no context on the
        // worker. after_commit=true (ADR-0007 §D8, global) backstops ordering: the mail job is not visible
        // on the queue until the transaction above commits, and is never enqueued on a rollback.
        // The brand palette is resolved HERE for the same reason the URL is: the worker has no tenant
        // context, so anything read from it there fails closed and the invitation would arrive unbranded
        // (H23a4). BrandPalette returns literal light-theme hexes — a scalar array, R3-legal payload.
        Notification::route('mail', $email)
            ->notify(
                (new TenantInvitationNotification($tenant->name, $this->buildInviteAcceptUrl($tenant, $plainToken)))
                    ->withBrand(BrandPalette::forTenant($tenant))
            );

        // Post-commit announcement (I3), raised ALONGSIDE the mail above rather than replacing it: the
        // accept URL cannot be rebuilt by a listener (the plaintext token dies with $plainToken) and must
        // never enter a webhook-visible payload anyway. See {@see MemberInvited} for the full argument.
        // This event tells the tenant's own admins that a seat was offered; the invitee's email is the
        // line above, unchanged since H22a.
        event(MemberInvited::for(
            $tenant,
            $email,
            $roleName,
            $invitedBy,
            $invite->invite_expires_at?->toIso8601String(),
        ));

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

        DB::transaction(function () use ($tenant, $newOwner, $currentOwnerId, $actor): void {
            // The incoming member's role before promotion — captured so the audit records the actual change.
            $newOwnerPriorRole = $newOwner->getRoleNames()->first();

            // tenants is the central, RLS-exempt table — a plain update.
            $tenant->forceFill(['owner_user_id' => $newOwner->id])->save();

            $newOwner->syncRoles(['owner']);

            // Audit the role changes (H4). auditable = `users` (the affected user's row, NOT the
            // model_has_roles pivot — that composite PK has no surrogate id audits.auditable_id can address;
            // spec §1). Each role change is one `permission_changed` row against the promoted/demoted user.
            $this->audit->record(
                AuditEvent::PermissionChanged,
                'users',
                (string) $newOwner->id,
                old: ['role' => $newOwnerPriorRole],
                new: ['role' => 'owner'],
                actorId: (string) $actor->getKey(),
            );

            if ($currentOwnerId !== null) {
                // The outgoing Owner is by definition an active member ⇒ visible under the current
                // tenant's `users` RLS policy, so a plain default-connection lookup resolves them.
                User::find($currentOwnerId)?->syncRoles(['admin']);

                $this->audit->record(
                    AuditEvent::PermissionChanged,
                    'users',
                    (string) $currentOwnerId,
                    old: ['role' => 'owner'],
                    new: ['role' => 'admin'],
                    actorId: (string) $actor->getKey(),
                );
            }

            // The ownership pointer change itself (spec §1: tenant.updated for owner_user_id changes).
            $this->audit->record(
                AuditEvent::Updated,
                'tenant',
                (string) $tenant->id,
                old: ['owner_user_id' => $currentOwnerId],
                new: ['owner_user_id' => $newOwner->id],
                actorId: (string) $actor->getKey(),
            );
        });
    }

    /**
     * The roster for the Members page: every active member + pending invite in the current tenant.
     *
     * RLS correctness: `tenant_users` is strict-RLS, so the query below returns exactly this tenant's
     * rows — the authoritative, tenant-bounded id set. Active members' `users` rows are visible on the
     * app connection, but pending/Invited placeholder users are hidden by the join-shape `users` policy;
     * so identities are resolved for that already-tenant-bounded id set on the pre-auth `pgsql_auth`
     * connection (the same cross-RLS lookup `invite()` uses) — this leaks nothing across tenants. Role:
     * active members resolve their materialized role from `model_has_roles` (team-scoped by RLS); pending
     * members show their reserved `invited_role_id`.
     *
     * @return list<array{user_id: string, name: string, email: string, status: string, role: string, is_owner: bool, joined_at: ?string, invited_at: ?string}>
     */
    public function listMembers(Tenant $tenant): array
    {
        $memberships = TenantUser::query()
            ->whereIn('status', [TenantUserStatus::Active->value, TenantUserStatus::Invited->value])
            ->get();

        if ($memberships->isEmpty()) {
            return [];
        }

        $userIds = $memberships->pluck('user_id')->all();

        // withTrashed so every membership's user resolves (a soft-deleted account still has a row) —
        // the FK then guarantees the keyed map contains every id below, so no null-identity branch.
        $users = User::on('pgsql_auth')
            ->withTrashed()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id')
            ->all();

        $globalRoleNames = Role::query()->whereNull('tenant_id')->pluck('name', 'id');

        $activeRoleByUser = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('roles.name', 'model_has_roles.model_id');

        $rows = [];
        foreach ($memberships as $m) {
            $user = $users[$m->user_id];

            if ($m->status === TenantUserStatus::Active) {
                $roleValue = $activeRoleByUser[$m->user_id] ?? null;
            } else {
                $roleValue = $m->invited_role_id !== null ? ($globalRoleNames[$m->invited_role_id] ?? null) : null;
            }

            $rows[] = [
                'user_id' => (string) $m->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $m->status->value,
                'role' => $roleValue !== null ? Str::headline((string) $roleValue) : '—',
                'is_owner' => $tenant->owner_user_id === $m->user_id,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'invited_at' => $m->invited_at?->toIso8601String(),
            ];
        }

        // Owner first, then active before pending, then by name — a stable, readable roster order.
        usort($rows, fn (array $a, array $b): int => ($b['is_owner'] <=> $a['is_owner'])
            ?: ($a['status'] <=> $b['status'])
            ?: ($a['name'] <=> $b['name']));

        return $rows;
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

    /**
     * The invitation accept URL — built in-request under live tenant context so the queued mail
     * notification (H3) carries a scalar string, never a Tenant model (§D5). Invitations are accepted on
     * the tenant's own subdomain (which is what establishes tenant context so the invite row is even
     * visible under RLS).
     *
     * H22a fixed two live defects here. This used to be
     * `$tenant->domains()->value('domain') ?? $tenant->slug` interpolated into `"https://{$domain}/…"`,
     * and both halves were wrong: `domains.domain` holds the SUBDOMAIN LABEL, so every invitation email
     * ever sent carried `https://acme/invitations/…`, a URL that resolves nowhere; and the hard-coded
     * scheme is wrong in local development, which serves on http.
     *
     * {@see TenantUrl::to()} — the APP arm, deliberately not toPublic(). `/invitations/{token}` lives in
     * a route group that still identifies by subdomain (ADR-0009 §D2 scopes custom domains to public
     * forms), so an invite link on a custom host would 302 the invitee to the central app.
     */
    private function buildInviteAcceptUrl(Tenant $tenant, string $plainToken): string
    {
        return TenantUrl::to($tenant, "invitations/{$plainToken}");
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
