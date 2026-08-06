<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The NULL-tenant audit write, enabled (Increment I5). **This migration is the deferred half of a decision
 * H4 already took, not a new one.** `2026_07_22_000001_create_audits_table.php`'s docblock says it in as
 * many words: *"A super-admin INSERT bypass would only be needed for a future NULL-tenant / cross-tenant
 * PLATFORM audit action, which H4 does not have; it is deferred to that action."*
 * {@see TenantIsolation::appendOnlySql()} names the same layering. I5 IS that action: the platform
 * settings the super-admin console writes (signup toggle, platform maintenance) belong to no tenant, so
 * their audit rows carry `tenant_id = NULL` and the strict INSERT policy — which compares `tenant_id` to
 * the ambient GUC — cannot pass them.
 *
 * ── WHAT THIS DOES **NOT** DO ───────────────────────────────────────────────────────────────────────────
 * **INSERT only. The ledger stays immutable, and no UPDATE or DELETE policy may ever be added to this
 * table by anyone, for any role.** The append-only guarantee is not prose: under FORCE ROW LEVEL SECURITY
 * a command with no matching policy is denied for EVERY role including this one, which is precisely how
 * §13's "immutable, never deleted" is enforced. Adding a policy "for completeness" would end that.
 *
 * ── THE CONSEQUENCE, STATED SO IT IS NOT LATER MISTAKEN FOR A BUG ───────────────────────────────────────
 * A NULL-tenant audit row is INVISIBLE to every tenant's /audit-log, because the base SELECT policy stays
 * strict (`tenant_id = ctx`) — that is the point: a tenant must not read the platform operator's actions,
 * and unlike `settings` this table is deliberately NOT nullable_global, which would leak them. There is no
 * platform-side audit viewer yet, so these rows are write-only-and-retained until one exists (I7/I11). The
 * audit spec §1 records that; do not "fix" the invisibility by widening the SELECT policy.
 *
 * Runs on the default `meridian_app` connection (it owns `audits`). Creates no table, so the migration
 * linter does not require withTenantIsolation() here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        // Table privileges are checked before RLS. INSERT only — no SELECT: the elevated role writes the
        // platform ledger, it does not read tenant history.
        DB::statement("GRANT INSERT ON audits TO {$role}");

        TenantIsolation::applySuperAdminBypass('audits', ['INSERT']);
    }

    public function down(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'audits' AND policyname LIKE 'audits_superadmin_%'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON audits');
        }

        DB::statement("REVOKE INSERT ON audits FROM {$role}");
    }
};
