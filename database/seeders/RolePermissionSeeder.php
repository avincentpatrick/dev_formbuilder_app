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
 * twenty-seven permissions, plus the role×permission grant matrix. These are GLOBAL rows (tenant_id IS
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
     * The 28-permission catalog (§5), dot-namespaced by domain.
     *
     * `scopes.manage` is the Increment-G10a addition — authoring the tenant's scoping hierarchy. It is a
     * genuinely new capability rather than a reuse of `tenant.settings.manage`: `ApiAbilities` maps the
     * `manage:settings` token ability onto that permission, so reusing it would retroactively hand every
     * already-minted settings token the authority to author authorization structure.
     */
    public const PERMISSIONS = [
        'tenant.settings.manage', 'tenant.billing.manage', 'tenant.billing.view',
        'tenant.members.invite', 'tenant.members.remove', 'tenant.roles.assign',
        'tenant.ownership.transfer',
        'forms.create', 'forms.edit.any', 'forms.edit.own', 'forms.publish.any',
        'forms.publish.own', 'forms.delete', 'forms.collaborators.manage',
        'submissions.create', 'submissions.edit.any', 'submissions.edit.own',
        'submissions.review.any', 'submissions.review.own', 'submissions.export',
        'submissions.view',
        'dashboard.org.view', 'dashboard.form.view',
        'webhooks.manage', 'audit_log.view',
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
            'tenant.ownership.transfer',
            'forms.create', 'forms.edit.any', 'forms.publish.any', 'forms.delete',
            'forms.collaborators.manage', 'scopes.manage',
            'submissions.create', 'submissions.edit.any', 'submissions.review.any',
            'submissions.export', 'submissions.view',
            'dashboard.org.view', 'dashboard.form.view',
            'webhooks.manage', 'audit_log.view',
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
            'webhooks.manage', 'audit_log.view',
            'feedback.submit', 'feedback.view',
        ],
        // Build/edit/publish forms they collaborate on; manual-encode + export those forms.
        'form_editor' => [
            'forms.create', 'forms.edit.own', 'forms.publish.own',
            'submissions.create', 'submissions.edit.own', 'submissions.export',
            'submissions.view', 'dashboard.form.view',
            'feedback.submit',
        ],
        // Review submissions on forms they collaborate on; may also encode + export those forms.
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
