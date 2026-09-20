<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the fixed, platform-defined RBAC catalog (multi-tenancy-rbac-design.md §3, §5): five roles and
 * thirty permissions (the 28th, `scopes.manage`, added in Increment G10a; the 29th, `integrations.manage`, in H15a; the 30th,
 * `tenant.members.two_factor_reset`, in M107 — the first minted since Phase 0, and the first to need a backfill
 * migration because nothing re-runs this seeder), plus the
 * role×permission grant matrix. These are GLOBAL rows (tenant_id IS
 * NULL) shared by every tenant — the catalog is closed (no UI ever inserts a sixth role).
 *
 * Written on the `pgsql_privileged` (superuser) connection, exactly like every other platform-global
 * seed: `roles`/`permissions` carry the nullable-global RLS shape, whose write policy stays strict, so
 * the ordinary `meridian_app` app role is (correctly) forbidden from authoring a NULL-tenant row. Only
 * the elevated role can. Idempotent: rows are matched by (name, guard_name) so re-running never
 * duplicates — important because tests seed the catalog on a connection outside RefreshDatabase's
 * rolled-back transaction.
 *
 * The catalog constants are public so tests assert against this single source of truth rather than a
 * hand-copied list.
 */
class RolePermissionSeeder extends Seeder
{
    public const GUARD = 'web';

    /** The fixed 5-role catalog (§3). Stored as plain data, not a PHP enum (see §3 design note). */
    public const ROLES = ['owner', 'admin', 'form_editor', 'reviewer', 'viewer'];

    /**
     * The 30-permission catalog (§5), dot-namespaced by domain.
     *
     * `scopes.manage` is the Increment-G10a addition — authoring the tenant's scoping hierarchy. It is a
     * genuinely new capability rather than a reuse of `tenant.settings.manage`: `ApiAbilities` maps the
     * `manage:settings` token ability onto that permission, so reusing it would retroactively hand every
     * already-minted settings token the authority to author authorization structure.
     *
     * `integrations.manage` is the H15a addition (ADR-0009) — holding the OAuth grants that let the platform
     * act inside a tenant's third-party workspace. New for the same reason, one step sharper: reusing
     * `webhooks.manage` would hand every already-minted `manage:webhooks` token authority over those
     * credentials, and the blast radius of that authority reaches outside this platform entirely.
     *
     * `tenant.members.two_factor_reset` is the `M107` addition (`D37`) — clearing somebody else's two-factor
     * enrolment so a tester who has lost their device and their recovery codes can sign in again. It is
     * **Owner only**, and Admin's omission is the decision rather than an oversight: `D37` says "a workspace
     * owner or the platform operator", and this matrix already expresses owner-only by leaving a key out of
     * `admin`, as `tenant.ownership.transfer` does.
     *
     * ⚠️ IT IS THE FIRST PERMISSION MINTED SINCE PHASE 0, AND THAT IS WHY IT NEEDED A MIGRATION. Every key
     * above reached an existing database only because it was already in this list when
     * `CreateTenantCommand` first ran the seeder. Nothing re-runs seeders afterwards — `deploy.ps1` runs
     * `migrate --force` and never `db:seed` — so a key added here alone exists in a fresh test database and
     * NOWHERE ELSE, and the `can:` middleware in front of it would deny the Owner on the testing server with
     * nothing in any log to explain the refusal. See the backfill migration named in §5's design note.
     *
     * ⛔ Reuse was considered and rejected twice. `tenant.members.remove` is seeded to Owner AND Admin,
     * which is wider than `D37`'s words; `tenant.ownership.transfer` is already owner-only but means
     * something else entirely, and overloading it would make the next reader's mental model wrong rather
     * than merely incomplete. I8a's precedent — reuse the dormant `tenant.roles.assign` rather than mint
     * `tenant.members.role` — does not reach here, because no dormant key describes this act.
     */
    public const PERMISSIONS = [
        'tenant.settings.manage', 'tenant.billing.manage', 'tenant.billing.view',
        'tenant.members.invite', 'tenant.members.remove', 'tenant.roles.assign',
        'tenant.ownership.transfer', 'tenant.members.two_factor_reset',
        'forms.create', 'forms.edit.any', 'forms.edit.own', 'forms.publish.any',
        'forms.publish.own', 'forms.delete', 'forms.collaborators.manage',
        'submissions.create', 'submissions.edit.any', 'submissions.edit.own',
        'submissions.review.any', 'submissions.review.own', 'submissions.export',
        'submissions.view',
        'dashboard.org.view', 'dashboard.form.view',
        'webhooks.manage', 'integrations.manage', 'audit_log.view',
        'feedback.submit', 'feedback.view',
        'scopes.manage',
    ];

    /**
     * The role → permission grant matrix (§5). `.own` permissions are held by Form Editor/Reviewer and
     * additionally gated per-resource by the Policy layer (`resource_grants` since Increment G10a, which
     * replaced `form_collaborators`); `.any` are the tenant-wide Owner/Admin grants.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        // Everything except the four `.own` per-form permissions (Owner acts tenant-wide via `.any`).
        'owner' => [
            'tenant.settings.manage', 'tenant.billing.manage', 'tenant.billing.view',
            'tenant.members.invite', 'tenant.members.remove', 'tenant.roles.assign',
            'tenant.ownership.transfer', 'tenant.members.two_factor_reset',
            'forms.create', 'forms.edit.any', 'forms.publish.any', 'forms.delete',
            'forms.collaborators.manage', 'scopes.manage',
            'submissions.create', 'submissions.edit.any', 'submissions.review.any',
            'submissions.export', 'submissions.view',
            'dashboard.org.view', 'dashboard.form.view',
            'webhooks.manage', 'integrations.manage', 'audit_log.view',
            'feedback.submit', 'feedback.view',
        ],
        // Owner minus tenant.billing.manage and tenant.ownership.transfer.
        'admin' => [
            'tenant.settings.manage', 'tenant.billing.view',
            'tenant.members.invite', 'tenant.members.remove', 'tenant.roles.assign',
            'forms.create', 'forms.edit.any', 'forms.publish.any', 'forms.delete',
            'forms.collaborators.manage', 'scopes.manage',
            'submissions.create', 'submissions.edit.any', 'submissions.review.any',
            'submissions.export', 'submissions.view',
            'dashboard.org.view', 'dashboard.form.view',
            'webhooks.manage', 'integrations.manage', 'audit_log.view',
            'feedback.submit', 'feedback.view',
        ],
        // Build/edit/publish forms they collaborate on; manual-encode + export those forms.
        'form_editor' => [
            'forms.create', 'forms.edit.own', 'forms.publish.own',
            'submissions.create', 'submissions.edit.own', 'submissions.export',
            'submissions.view', 'dashboard.form.view',
            'feedback.submit',
        ],
        // Review + export submissions on forms they collaborate on.
        //
        // ⛔ `submissions.create` HERE IS NOT "MAY ENCODE", AND THIS COMMENT SAID IT WAS UNTIL M77.
        // `SubmissionPolicy::create()` has required `forms.edit.any` OR **editor** capacity on the form
        // since the G10a tightening (encoding is an authoring act), and a reviewer's grant is reviewer
        // capacity — so a plain Reviewer can encode on NO form at all. The permission stays because it
        // is load-bearing in two places: it is the coarse half a Reviewer who ALSO holds an editor grant
        // needs in order to encode, and it is what entitles the `write:submissions` API ability. Dropping
        // it would break a working configuration; only the sentence was wrong.
        //
        // ⚠️ `submissions.review.own` is the one that is capacity-INSENSITIVE: it resolves through
        // `ResourceGrantResolver::holdsAny()`, which accepts a grant of either capacity.
        'reviewer' => [
            'submissions.create', 'submissions.review.own', 'submissions.export',
            'submissions.view', 'dashboard.form.view',
            'feedback.submit',
        ],
        // Read-only: org + per-form dashboards and submission lists. No Audit Log (Owner/Admin only).
        'viewer' => [
            'submissions.view', 'dashboard.org.view', 'dashboard.form.view',
            'feedback.submit',
        ],
    ];

    public function run(): void
    {
        $connection = DB::connection('pgsql_privileged');
        $now = now();

        // 1. Permissions — global rows.
        /** @var array<string, string> $permissionIds name => uuid */
        $permissionIds = [];
        foreach (self::PERMISSIONS as $name) {
            $permissionIds[$name] = $this->firstOrCreateId($connection, 'permissions', $name, $now);
        }

        // 2. Roles — global rows.
        /** @var array<string, string> $roleIds name => uuid */
        $roleIds = [];
        foreach (self::ROLES as $name) {
            $roleIds[$name] = $this->firstOrCreateId($connection, 'roles', $name, $now);
        }

        // 3. The grant matrix — role_has_permissions (global-to-global).
        foreach (self::MATRIX as $role => $permissions) {
            foreach ($permissions as $permission) {
                $connection->table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleIds[$role],
                    'permission_id' => $permissionIds[$permission],
                ]);
            }
        }

        // The catalog changed underneath Spatie's cache — flush it so the app resolves the fresh rows.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Return the id of the global (tenant_id NULL) role/permission row named `$name`, inserting a
     * UUIDv7-keyed row if absent. Matched on (name, guard_name) so re-runs are idempotent — the
     * Postgres unique index treats NULL tenant_id as distinct, so app-layer matching is what actually
     * prevents duplicate global rows.
     */
    private function firstOrCreateId(ConnectionInterface $connection, string $table, string $name, mixed $now): string
    {
        $existing = $connection->table($table)
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Uuid::uuid7()->toString();
        $connection->table($table)->insert([
            'id' => $id,
            'tenant_id' => null,
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
