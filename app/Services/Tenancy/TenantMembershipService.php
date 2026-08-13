<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\AuditEvent;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Events\MemberInvited;
use App\Events\MemberJoined;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Admin\SuperAdminService;
use App\Services\Entitlements\QuotaGuard;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\BrandPalette;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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

    /**
     * Join an OPEN workspace by having just registered on its subdomain (Increment I5, PRD Feature #10's
     * "whether new members can self-register or must be invited").
     *
     * This is the only membership write in the file that runs with **no ambient tenant context**: it is
     * called from a `Registered` listener on Fortify's `/register`, whose middleware list carries no
     * tenancy middleware at all. So it borrows the tenant's context itself — inside `DB::transaction`,
     * because {@see TenantContext::applyLocal()} is `SET LOCAL` and a silent no-op outside one, and
     * `tenant_users` is strict-RLS: without the GUC the INSERT is REFUSED, not mis-scoped. Both the DB GUC
     * and Spatie's permissions team id are restored in `finally`, the {@see SuperAdminService}
     * adopt-and-restore precedent.
     *
     * Role `viewer` deliberately: the least-privileged role in the catalog. Someone who joined by knowing a
     * URL has proved nothing about what they should be able to do, and an Owner can promote them from the
     * Members page — {@see self::changeRole()}, which I8a built precisely because this sentence had been
     * promising a surface that did not exist. `syncRoles` (not `assignRole`) keeps §7's one-role-per-tenant
     * invariant.
     *
     * ── RETURNS NULL WHEN THE SEAT QUOTA IS FULL, AND DOES NOT THROW ───────────────────────────────────
     * {@see invite()} above asserts the same quota and lets {@see QuotaExceededException} become a 402 —
     * correct there, because the Admin who triggered it can act on it. Here the caller is a person who has
     * just had an account created for them by a DIFFERENT service in a DIFFERENT transaction that has
     * already committed: a 402 would leave them with a live account, no workspace, and an upgrade prompt
     * addressed to somebody else. Returning null lands them in exactly the state a central-host
     * registration produces (an account with no membership), which is a state the product already has.
     * The workspace's Owner sees no new member, which is the correct outcome for a full workspace.
     */
    public function joinOpenTenant(Tenant $tenant, User $user, string $roleName = 'viewer'): ?TenantUser
    {
        return $this->attachMember($tenant, $user, $roleName, 'self_registration');
    }

    /**
     * Materialize the membership an identity provider's assertion implies (P1b — ADR-0016 §D7).
     *
     * Shares {@see joinOpenTenant()}'s body entirely, and the sharing is the point: the context borrow, the
     * `SET LOCAL` transaction, the seat-quota reservation, the reuse of a prior Declined/Removed row and
     * the one-role-per-tenant `syncRoles` are all the same problem, and a second implementation would be
     * right until the day one of them changed. What differs is one string — the audit's `via` — because
     * "how did this person get in" is the only question the two doors answer differently, and the ledger is
     * the only place the answer survives.
     *
     * ⚠️ THE ROLE IS THE TENANT'S CHOICE, NOT A DEFAULT. `sso_connections.default_role_name` is
     * CHECK-constrained to the seeded catalog MINUS `owner` (see `AssignableRoles`), because RBAC §5
     * establishes Owner only by ownership transfer and an IdP attribute must never be a path to it.
     *
     * ⚠️ AND THE CALLER MUST HAVE ALREADY REFUSED A SUSPENDED MEMBERSHIP. This method reactivates any
     * non-Active row, which is correct for both doors' "not currently a member" states — but `Suspended` is
     * an explicit administrative sanction and reactivating it would let SSO launder a decision an admin
     * made. `App\Services\Sso\SsoUserProvisioner` checks that before calling here — named in prose rather
     * than through `{@see}` on purpose, so this generic tenancy service imports nothing from the SSO seam
     * and the dependency keeps pointing one way.
     */
    public function joinViaSso(Tenant $tenant, User $user, string $roleName): ?TenantUser
    {
        return $this->attachMember($tenant, $user, $roleName, 'sso_jit');
    }

    /**
     * The shared membership write. See {@see joinOpenTenant()} for the context-borrow and quota reasoning.
     *
     * @param  string  $via  how the member arrived, for the audit payload — the only difference between the
     *                       self-registration door and the SSO one
     */
    private function attachMember(Tenant $tenant, User $user, string $roleName, string $via): ?TenantUser
    {
        $role = Role::query()->where('name', $roleName)->whereNull('tenant_id')->first();

        if ($role === null) {
            throw MembershipException::unknownRole($roleName);
        }

        $tenantId = (string) $tenant->getKey();
        $userId = (string) $user->getKey();

        $savedTenant = TenantContext::currentTenantId();
        $savedUser = TenantContext::currentUserId();
        $savedTeam = app(PermissionRegistrar::class)->getPermissionsTeamId();

        return DB::transaction(function () use ($tenant, $user, $role, $tenantId, $userId, $via, $savedTenant, $savedUser, $savedTeam): ?TenantUser {
            TenantContext::applyLocal($tenantId, $userId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

            try {
                // Reuse a prior row rather than duplicating it — (tenant_id, user_id) is unique, and a
                // person who declined an invitation last month may legitimately walk in the front door now.
                $existing = TenantUser::query()->where('user_id', $userId)->first();

                // ⚠️ SILENT STATE 1 OF 2 — NOTHING HAPPENED, SO NOTHING IS ANNOUNCED. This person is already
                // a member; they just registered a second time. `MemberJoined` is raised BELOW this return,
                // so the Owner is not told that someone "joined" who has been here for months. Do not hoist
                // the emission above this line.
                if ($existing !== null && $existing->status === TenantUserStatus::Active) {
                    return $existing;
                }

                try {
                    $this->quota->assertCanCreate(UsageMetric::ActiveSeats);
                } catch (QuotaExceededException) {
                    // ⚠️ SILENT STATE 2 OF 2 — the workspace is full, so no membership was created and there
                    // is nothing to announce. Same rule as above: `MemberJoined` is raised below this return.
                    // Telling an Owner that a member joined a workspace that refused them would be worse than
                    // silence, which is the outcome the docblock above already calls correct.
                    return null;
                }

                $membership = $existing ?? new TenantUser;
                $membership->fill([
                    'user_id' => $userId,
                    'status' => TenantUserStatus::Active,
                    'invited_role_id' => $role->id,
                    'invited_by' => null,
                    'invited_at' => null,
                    'invite_expires_at' => null,
                    'invite_token' => null,
                    'joined_at' => now(),
                    'removed_at' => null,
                    'removed_by' => null,
                ])->save(); // BelongsToTenant fills tenant_id from the context borrowed above

                $user->syncRoles([$role]);

                // `via` is what distinguishes this row from an accepted invitation in the ledger — the
                // three doors produce the same membership and the same role, and only the audit says which
                // one it came through. Keyed on the membership's own uuid (tenant_users.id IS a uuid, so
                // I2's 22P02 hazard does not apply here).
                $this->audit->record(
                    AuditEvent::Created,
                    'tenant_users',
                    (string) $membership->getKey(),
                    new: [
                        'user_id' => $userId,
                        'status' => TenantUserStatus::Active->value,
                        'role' => $role->name,
                        'via' => $via,
                    ],
                    actorId: $userId,
                );

                // ⚠️ INSIDE THE TRANSACTION, WHICH IS THE OPPOSITE OF DomainEvent'S POST-COMMIT RULE — and
                // required rather than merely tolerated. `TenantContext::applyLocal()` is `SET LOCAL`, so the
                // RLS GUC dies with this transaction; `notifications` is strict-RLS, so the listener's INSERT
                // would be REFUSED (not mis-scoped) if this were raised after commit. Spatie's team id is
                // restored in the same `finally`, and the recipient resolver needs it to answer who holds
                // owner/admin here. `MemberJoined` is deliberately NOT a DomainEvent — see its docblock for
                // what that costs. The queued email is still post-commit: `after_commit` is on globally.
                //
                // ⚠️ THIS NOW FIRES FOR EVERY `$via`, INCLUDING SSO JIT PROVISIONING — a consequence of P1b
                // hoisting `joinOpenTenant()`'s body into this shared `attachMember()`, and a DECISION rather
                // than an accident of the merge. It is the right one: `member_joined` answers "who is in this
                // workspace, and when did they arrive", which is exactly as true of someone their IdP let in
                // as of someone who used the open-registration door. `$via` already distinguishes the two in
                // the audit ledger, where the distinction belongs; the bell row and its email deliberately do
                // not, because the Owner's question is the same either way.
                event(MemberJoined::for($tenant, $user, $role->name));

                return $membership;
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
                app(PermissionRegistrar::class)->setPermissionsTeamId($savedTeam);
            }
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
     * Change an active member's tenant-scoped role — Increment I8a (PRD Feature #14).
     *
     * ⚠️ THIS SURFACE WAS PROMISED BEFORE IT EXISTED. {@see self::joinOpenTenant()}'s docblock has told
     * readers since H-phase that "an Owner can promote them on the Members page in two clicks", and PRD
     * Feature #14 names "role changes" among the actions step-up must gate — but there was no route, no
     * controller method and no service method, so the criterion was vacuously satisfiable and the invite
     * copy was untrue. Built here so the gate has something real to guard.
     *
     * ── THE FOUR REFUSALS, AND WHY THEY ARE HERE RATHER THAN IN THE REQUEST ────────────────────────────
     * A FormRequest can validate that `role` is one of four strings; it cannot know that this particular
     * user is the Owner, or is the actor. Those are role-model invariants (§5, §7) and they belong beside
     * {@see self::transferOwnership()}, which enforces the same Owner-uniqueness rule from the other side:
     *   · the Owner's own role is immutable here — ownership moves only by transfer, so a "demote the
     *     Owner" path would leave the tenant ownerless while `tenants.owner_user_id` still pointed at them;
     *   · `owner` is not assignable — the mirror of {@see self::invite()}'s cannotInviteAsOwner();
     *   · an actor cannot change their OWN role — otherwise the last Admin can demote themselves out of
     *     the ability to promote anyone back, locking the workspace's administration with no way in short
     *     of an operator; and an Admin self-promoting is a privilege escalation with a straight face;
     *   · a no-op is refused rather than silently audited, so the ledger never carries `admin → admin`.
     *
     * One `permission_changed` audit row against the affected user's `users` row — the SAME alias, event
     * and auditable_type transferOwnership() already writes for exactly this kind of change, so both
     * appear together under the audit viewer's "this resource's history" filter. Not the `model_has_roles`
     * pivot: its composite PK has no surrogate id `audits.auditable_id` could address (audit spec §1).
     *
     * NO NOTIFICATION, deliberately. A `member.role_changed` event would need a new `DomainEventType` and
     * `NotificationType` case, and I3 recorded the trap that comes with one: the exhaustive `match` arms
     * in WebhookEndpointPresenter and ConnectionPresenter throw `UnhandledMatchError` on an unhandled case.
     * That is a real feature with a real cost and it is not what PRD #14 asked for; `docs/feature-backlog.md`
     * carries the row.
     *
     * `syncRoles` (not `assignRole`) preserves §7's one-role-per-tenant invariant, matching every other
     * writer in this class.
     */
    public function changeRole(Tenant $tenant, User $member, string $roleName, User $actor): void
    {
        if ($roleName === 'owner') {
            throw MembershipException::cannotAssignOwner();
        }

        if ($tenant->owner_user_id === $member->id) {
            throw MembershipException::cannotChangeOwnerRole();
        }

        if ($actor->id === $member->id) {
            throw MembershipException::cannotChangeOwnRole();
        }

        $role = Role::query()->where('name', $roleName)->whereNull('tenant_id')->first();
        if ($role === null) {
            throw MembershipException::unknownRole($roleName);
        }

        $membership = TenantUser::query()->where('user_id', $member->id)->first();
        if ($membership === null || $membership->status !== TenantUserStatus::Active) {
            throw MembershipException::notAMember();
        }

        $priorRole = $member->getRoleNames()->first();
        if ($priorRole === $roleName) {
            throw MembershipException::roleUnchanged($roleName);
        }

        DB::transaction(function () use ($member, $roleName, $priorRole, $actor): void {
            $member->syncRoles([$roleName]);

            $this->audit->record(
                AuditEvent::PermissionChanged,
                'users',
                (string) $member->id,
                old: ['role' => $priorRole],
                new: ['role' => $roleName],
                actorId: (string) $actor->getKey(),
            );
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
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * ⚠️ `$terms` FILTERS IN PHP, OVER `$rows`, AFTER THE `pgsql_auth` HOP. NEVER IN THE QUERY BELOW.
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * The paragraph above says why the hop is safe: the id set is already tenant-bounded, and this method
     * adds no predicate of its own. A KEYWORD IS A PREDICATE, and pushing one into that `whereIn` would
     * repeal the only reason the hop is defensible. J1c measured what that costs on the seeded corpus — a
     * demo admin running `email ILIKE '%o%'`:
     *
     *   on pgsql_auth   -> 8 rows, INCLUDING owner@northwind.test  (another tenant's user)
     *   on the app conn -> 6 rows, demo's active members only
     *
     * So the filter runs here, in PHP, over rows that are already this tenant's by construction. It is not
     * a compromise: `$rows` is one roster — tens to low hundreds — and {@see SearchTerms::matchesAny()} is
     * the same AND-across-tokens/OR-across-fields rule `KeywordFilter::applyLike()` gives in SQL, so the
     * roster and `MemberSearchArm` agree about what matches. **The standing rule, broader than this file
     * and recorded in RBAC §9: no user-supplied predicate may ever run on `pgsql_auth`.**
     *
     * ⚠️ ONE DELIBERATE ASYMMETRY WITH GLOBAL SEARCH, AND IT RUNS THE SAFE DIRECTION. This filter finds
     * PENDING INVITES; `MemberSearchArm` structurally cannot, because `users_visibility` admits only
     * `tu.status = 'active'` and RLS applies at every reference to `users` (measured in J1c, not reasoned).
     * That is correct on both sides: this page has ALREADY fetched those identities and renders them, so
     * filtering the list it is showing discloses nothing new — while global search must not go and fetch
     * them. `MembersRosterFilterTest` pins both directions so the asymmetry cannot be "fixed" by accident.
     *
     * `$terms` is OPTIONAL so `MembersIndexTest` passes unedited, for the reason J1b records about
     * `FormListScopingTest`.
     *
     * @return list<array{user_id: string, name: string, email: string, status: string, role: string, is_owner: bool, joined_at: ?string, invited_at: ?string}>
     */
    public function listMembers(Tenant $tenant, ?SearchTerms $terms = null): array
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

            // The keyword gate — see the ⚠️ block on this method. Applied HERE rather than in a `->where()`
            // above, and applied to the SAME two fields the roster renders and `MemberSearchArm` matches.
            if ($terms !== null && ! $terms->matchesAny((string) $user->name, (string) $user->email)) {
                continue;
            }

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

    /**
     * The global identity behind an email address, resolved across the `users` RLS boundary.
     *
     * ⚠️ PUBLIC SINCE P1b, WITH ONE RULE ATTACHED. `pgsql_auth` carries a permissive `TO meridian_auth`
     * carve-out, so the join-shape visibility policy is OR'd away entirely and this query sees EVERY user
     * in the deployment — including members of other tenants. That is exactly what makes it the right tool
     * for "is this email already an account anywhere?" and exactly what makes it dangerous:
     * **no user-supplied predicate may ever run on this connection** (RBAC §9, and
     * `MemberSearchArm` documents the same rule from the other side). Exact-email
     * equality only. A `LIKE`, an `orWhere`, or a caller-chosen column here turns a uniqueness check into a
     * cross-tenant directory.
     *
     * The model is hopped back onto the default connection before it is returned, so nothing downstream
     * writes through the pre-auth role — the `CreateNewUser` / `InvitationController` idiom.
     */
    public function resolveUserByEmail(string $email): ?User
    {
        $user = User::on('pgsql_auth')->where('email', $email)->first();
        $user?->setConnection((string) config('database.default'));

        return $user;
    }
}
