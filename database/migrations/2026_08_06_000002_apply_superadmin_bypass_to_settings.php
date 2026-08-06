<?php

declare(strict_types=1);

use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer the super-admin carve-out onto `settings` (Increment I5, RBAC §9) — the FIRST bypass in the
 * deployment to grant anything beyond SELECT, which is why it gets its own file and its own argument.
 *
 * `settings` is nullable_global: its SELECT policy already reveals platform rows to everyone, but its
 * INSERT/UPDATE policies are STRICT (`tenant_id = current_setting(...)`), deliberately, so no tenant
 * connection can author a platform row. That leaves the platform console with no way to write its own
 * settings at all — and the failure mode is the quiet one: a naive UPDATE on the app connection matches
 * ZERO rows and reports success. `SettingsRlsTest` and `PlatformSettingsWriteTest` both pin that no-op.
 *
 * So the console's two writes (signup toggle, platform maintenance) go through
 * {@see SuperAdminService::updatePlatformSettings()} on the elevated
 * `pgsql_superadmin` connection, gated on the transaction-local `app.is_superadmin_context` GUC that
 * {@see SuperAdminContext::applyLocal()} is the only thing that opens.
 *
 * SELECT is granted alongside INSERT/UPDATE because `updateOrCreate` reads before it writes — without it
 * every platform write would insert a duplicate and hit settings_platform_key_unique.
 *
 * Table privileges are checked BEFORE RLS, so the GRANT is not optional decoration: without it the query
 * fails "permission denied" before any policy is consulted, and the test that proves the POLICY (elevated
 * connection, GUC closed) would pass for the wrong reason. Both are asserted separately.
 *
 * Runs on the default `meridian_app` connection: it owns `settings`, so it is who can GRANT + CREATE POLICY.
 * Creates no table, so the migration linter does not require withTenantIsolation() here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        DB::statement("GRANT SELECT, INSERT, UPDATE ON settings TO {$role}");

        TenantIsolation::applySuperAdminBypass('settings', ['INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'settings' AND policyname LIKE 'settings_superadmin_%'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON settings');
        }

        DB::statement("REVOKE SELECT, INSERT, UPDATE ON settings FROM {$role}");
    }
};
