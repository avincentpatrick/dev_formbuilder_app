<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer the super-admin cross-tenant carve-out onto `users` (Increment B2c, RBAC §9). The join-shape
 * `users` visibility policy (B1) hides a user from anyone who is not a co-tenant — correct for tenant
 * request code, but the platform console must list users across every tenant. This adds a permissive
 * SELECT policy scoped `TO meridian_superadmin`, gated on `app.is_superadmin_context = 'true'`, so the
 * elevated role sees all users only while SuperAdminService has opened that context (transaction-local).
 *
 * SELECT only: B2c reads users cross-tenant, never writes them. Table privileges are checked BEFORE
 * RLS, so the elevated role also needs a GRANT (mirroring apply_users_rls's GRANT to meridian_auth) —
 * without it the query fails "permission denied" before any policy is even consulted.
 *
 * Also GRANT SELECT ON tenant_users: the `users` visibility policy's expression contains an
 * EXISTS(SELECT 1 FROM tenant_users …) subquery, which Postgres evaluates for EVERY role that reads
 * `users` (the permissive policies are OR'd into the query), so the elevated role needs table-level
 * access to tenant_users even though its own rows there stay RLS-filtered to zero (no bypass on
 * tenant_users) — the super-admin's user visibility comes entirely from the gated carve-out, not the
 * membership join.
 *
 * Runs on the default `meridian_app` connection: it owns `users`, so it is who can GRANT + CREATE
 * POLICY. `users` has no `tenant_id` column and this migration creates no table, so the migration
 * linter does not require withTenantIsolation() here (same footing as apply_users_rls).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        // Table-privilege grants (checked before RLS). READ-only in B2c. tenant_users is needed only so
        // the users visibility policy's EXISTS subquery is evaluable for this role (see class docblock).
        DB::statement("GRANT SELECT ON users TO {$role}");
        DB::statement("GRANT SELECT ON tenant_users TO {$role}");

        TenantIsolation::applySuperAdminBypass('users', ['SELECT']);
    }

    public function down(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'users' AND policyname LIKE 'users_superadmin_%'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON users');
        }

        DB::statement("REVOKE SELECT ON users FROM {$role}");
        DB::statement("REVOKE SELECT ON tenant_users FROM {$role}");
    }
};
