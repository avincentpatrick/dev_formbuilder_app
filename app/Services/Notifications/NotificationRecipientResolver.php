<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Who should be told?" for the notification types that address a ROLE rather than a person (Increment I3).
 *
 * ── The rule is {@see SubmissionPolicy::view()}, not a new one ──────────────────────────────────────────
 * Owner/Admin/Viewer see submissions tenant-wide because they hold `dashboard.org.view`; Form Editor and
 * Reviewer see only forms they hold a grant on. If this class invented its own answer, the bell would
 * announce submissions the inbox then 403s on — a leak of a form title and the fact of a submission, to
 * someone the authorization model says must not have them. So the same two predicates are used here, one
 * hoisted from a per-user check to a set: `dashboard.org.view` decides the org-wide half, and
 * {@see ResourceGrantResolver} decides the collaborator half.
 *
 * ── Three queries, flat, whatever the tenant's size ─────────────────────────────────────────────────────
 * This runs on a SYNCHRONOUS listener, and for `submission_received` that listener is inside a public guest
 * POST. A per-candidate `holdsAny()` would put one grant query per collaborator-scoped member into that
 * request. {@see ResourceGrantResolver::primeFor()} loads them all in one, after which every decision is in
 * memory. The cost is bounded by the tenant's grant count, never by its member count.
 *
 * The types with an empty {@see NotificationType::recipientRoles()} never reach this class: their emitting
 * site knows exactly who it means (the submission's respondent, the export's requester, the tenant owner)
 * and guessing would be worse than asking.
 */
final class NotificationRecipientResolver
{
    /** @var array<string, list<string>> tenant id => role names that carry `dashboard.org.view` */
    private array $orgWideRoles = [];

    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * @param  ?Form  $form  the form the notification is about; required for a form-scoped type. The MODEL,
     *                       not an id: {@see ResourceGrantResolver::holdsAny()}'s id branch re-anchors the
     *                       form against the database on every call, which would be one query per candidate
     *                       — the exact N+1 the prime below exists to remove.
     * @param  ?string  $exceptUserId  the actor — nobody is notified about their own act
     * @return list<string> user ids
     */
    public function forType(NotificationType $type, ?Form $form = null, ?string $exceptUserId = null): array
    {
        $roles = $type->recipientRoles();

        if ($roles === []) {
            return [];
        }

        $roleByUser = $this->activeMembersHoldingRoles($roles);

        if ($roleByUser === []) {
            return [];
        }

        $orgWide = $this->orgWideRoleNames();

        // Null unless this type is narrowed by per-form visibility AND the caller supplied the form. A
        // form-scoped type arriving without one would be a bug at the call site, and the effect below is
        // that collaborator-scoped members are dropped rather than told about something nobody checked
        // they can see.
        $scopedForm = $type->isFormScoped() ? $form : null;

        // Only the candidates whose role is NOT org-wide need a grant decision — usually a small subset,
        // and often none at all.
        $collaborators = $scopedForm === null
            ? []
            : array_keys(array_filter($roleByUser, static fn (string $role): bool => ! in_array($role, $orgWide, true)));

        if ($collaborators !== []) {
            $this->grants->primeFor($collaborators);
        }

        $recipients = [];

        foreach ($roleByUser as $userId => $roleName) {
            if ($userId === $exceptUserId) {
                continue;
            }

            if (in_array($roleName, $orgWide, true)) {
                $recipients[] = $userId;

                continue;
            }

            if ($scopedForm !== null && $this->grants->holdsAny($this->stubUser($userId), $scopedForm)) {
                $recipients[] = $userId;
            }
        }

        return $recipients;
    }

    /**
     * Active members of the current tenant holding any of these roles.
     *
     * RLS supplies every tenant predicate: `model_has_roles` is strict with `tenant_id` as the team key and
     * `tenant_users` is strict, so this cannot see another tenant's membership. The `tenant_users` join is
     * not belt-and-braces — {@see TenantMembershipService::remove()} clears roles, but
     * a Declined or Suspended row can still carry one, and {@see ResourceGrantResolver} pays for exactly
     * this check for exactly this reason.
     *
     * @param  list<string>  $roles
     * @return array<string, string> user id => role name
     */
    private function activeMembersHoldingRoles(array $roles): array
    {
        /** @var array<string, string> $rows */
        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('tenant_users', function (JoinClause $join): void {
                $join->on('tenant_users.user_id', '=', 'model_has_roles.model_id')
                    ->where('tenant_users.status', '=', TenantUserStatus::Active->value);
            })
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', $roles)
            ->pluck('roles.name', 'model_has_roles.model_id')
            ->all();

        return $rows;
    }

    /**
     * The role names that carry `dashboard.org.view` — DERIVED from the permission catalog, never
     * hard-coded and never read off `RolePermissionSeeder::MATRIX`.
     *
     * `dashboard.org.view` is the single permission {@see SubmissionPolicy::hasOrgWideVisibility()} tests,
     * so this query IS the policy's own predicate, lifted to a set. Reading a seeder constant to make an
     * authorization-shaped decision would couple app behaviour to test fixtures and survive right up until
     * someone edits the matrix and nothing fails.
     *
     * @return list<string>
     */
    private function orgWideRoleNames(): array
    {
        $key = TenantContext::currentTenantId() ?? '-';

        if (isset($this->orgWideRoles[$key])) {
            return $this->orgWideRoles[$key];
        }

        /** @var list<string> $names */
        $names = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', 'dashboard.org.view')
            ->pluck('roles.name')
            ->all();

        return $this->orgWideRoles[$key] = $names;
    }

    /**
     * {@see ResourceGrantResolver} keys its memo on `$user->getKey()` and reads nothing else off the model,
     * so an unsaved instance carrying the id is enough — and avoids hydrating every candidate just to ask a
     * question already answered in memory by the prime above.
     */
    private function stubUser(string $userId): User
    {
        $user = new User;
        $user->forceFill(['id' => $userId]);
        $user->exists = true;

        return $user;
    }
}
