<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

/**
 * Put `tenant.members.two_factor_reset` into databases that already exist — Increment M107 (`D37`).
 *
 * ── WHY A MIGRATION AT ALL, WHEN THE SEEDER ALREADY LISTS IT ───────────────────────────────────────────
 * `RolePermissionSeeder` writes the RBAC catalog exactly ONCE per database, at `CreateTenantCommand`
 * (`:102`). **Nothing re-runs it afterwards.** `deploy.ps1` runs `migrate --force` and has no `db:seed`
 * step at all — verified, not assumed — and no migration in this tree has ever inserted a permission row,
 * because until now no permission has been minted since Phase 0.
 *
 * ⛔ SO A KEY ADDED TO THE SEEDER ALONE EXISTS IN A FRESH TEST DATABASE AND NOWHERE ELSE. Every local suite
 * would pass. On the testing server, `can:tenant.members.two_factor_reset` would resolve against a
 * `permissions` table that has never heard of it, Spatie would deny, and the Owner would meet a 403 on the
 * one feature built to rescue somebody who cannot sign in — with nothing in any log naming the cause.
 *
 * This is the same shape as `audits_event_check`: *adding a case to the PHP enum does nothing to a database
 * that already exists.* That lesson had been learned for CHECK constraints and written down twice
 * (`2026_08_10_000002`, and the data dictionary's §13 note); it had not been learned for the RBAC catalog,
 * because the catalog had never grown. It has now.
 *
 * ── LITERALS, DELIBERATELY, AND NOT `RolePermissionSeeder::PERMISSIONS` ────────────────────────────────
 * The key and the role are spelled out here rather than read from the seeder. A migration is a dated record
 * of what happened to a database on a particular day; reading a live constant would mean a future edit to
 * that constant silently changed what THIS migration did, which is the defect `2026_08_10_000002`'s
 * `PRE_I11B_EVENTS` avoids in the other direction. The seeder stays the source of truth for a FRESH
 * database; this file is the source of truth for the databases that already existed on 2026-09-20.
 *
 * ── IDEMPOTENT, BECAUSE IT MUST BE SAFE BESIDE THE SEEDER ──────────────────────────────────────────────
 * A fresh database runs the seeder AND this migration, in either order. Both match on
 * `(name, guard_name)` with `tenant_id IS NULL` at the app layer — the Postgres unique index treats NULL
 * `tenant_id` as distinct, so app-layer matching is what actually prevents a duplicate global row, exactly
 * as `RolePermissionSeeder::firstOrCreateId()` documents.
 *
 * ── THE PRIVILEGED CONNECTION, FOR THE REASON THE SEEDER USES IT ───────────────────────────────────────
 * `permissions`, `roles` and `role_has_permissions` carry RLS. The seeder writes them over
 * `pgsql_privileged`; a migration running as the app role with no tenant context would match no policy and
 * affect ZERO ROWS while throwing nothing — the silent-zero-row family this repository has now met on six
 * Fortify endpoints, one recovery-code rotation and one `users` backfill.
 *
 * ── `down()` DETACHES BUT DOES NOT DELETE THE PERMISSION ROW ───────────────────────────────────────────
 * Removing the grant is the honest inverse of adding it. Deleting the `permissions` row itself is not:
 * `model_has_permissions` may carry direct assignments this migration never made, and a foreign key that
 * refuses is a worse rollback than one that leaves an unreferenced catalog row behind.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.members.two_factor_reset';

    private const ROLE = 'owner';

    private const GUARD = 'web';

    public function up(): void
    {
        $connection = DB::connection('pgsql_privileged');
        $now = now();

        $permissionId = $connection->table('permissions')
            ->whereNull('tenant_id')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = Uuid::uuid7()->toString();

            $connection->table('permissions')->insert([
                'id' => $permissionId,
                'tenant_id' => null,
                'name' => self::PERMISSION,
                'guard_name' => self::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = $connection->table('roles')
            ->whereNull('tenant_id')
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');

        // ⚠️ A database with no global `owner` role has never run the seeder, so it has no catalog for this
        // grant to join. That is not this migration's business to repair, and inventing the role here would
        // create a second, competing catalog writer. Leave the permission row in place and stop.
        if ($roleId === null) {
            return;
        }

        $connection->table('role_has_permissions')->updateOrInsert([
            'role_id' => (string) $roleId,
            'permission_id' => (string) $permissionId,
        ]);

        // The catalog changed underneath Spatie's cache — flush it, exactly as the seeder does, or the
        // first request after this deploy resolves the pre-migration catalog and denies.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $connection = DB::connection('pgsql_privileged');

        $permissionId = $connection->table('permissions')
            ->whereNull('tenant_id')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        $roleId = $connection->table('roles')
            ->whereNull('tenant_id')
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId !== null && $roleId !== null) {
            $connection->table('role_has_permissions')
                ->where('role_id', (string) $roleId)
                ->where('permission_id', (string) $permissionId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
