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
 * and unlike `settings` this table is deliberately NOT nullable_global, which would leak them.
 *
 * ── I7b BUILT THE VIEWER, AND THE PARAGRAPH ABOVE STILL STANDS AS WRITTEN ───────────────────────────────
 * This file used to end "there is no platform-side audit viewer yet, so these rows are write-only-and-
 * retained until one exists (I7/I11) … do not 'fix' the invisibility by widening the SELECT policy."
 * `GET /admin/audit-log` (I7b) is that viewer, and it did NOT take the widening this file forbids. It reads
 * through a SEPARATE, deliberately narrower policy — `audits_platform_select`, added by
 * `2026_08_08_000001_apply_platform_audit_read_to_audits.php` via {@see TenantIsolation::platformRowsBypass()} —
 * whose USING clause is `current_setting('app.is_superadmin_context', true) = 'true' AND tenant_id IS NULL`.
 * The base `audits_tenant_select` is untouched, so **a tenant still cannot read a platform row**; and
 * because of that second conjunct, **the operator cannot read a tenant row either**. Both directions of the
 * wall stand, and `PlatformAuditRlsTest` asserts both rather than promising them.
 *
 * **Do not replace that policy with `TenantIsolation::applySuperAdminBypass('audits', ['SELECT'])`.** The
 * generic helper's gate is unrestricted; on this table it would hand the platform operator every tenant's
 * complete history. That is the widening this file has always forbidden, and it is still forbidden.
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

        // Table privileges are checked before RLS. INSERT here; I7b's own migration adds the paired
        // GRANT SELECT. The second half of this comment's original claim still holds, and is now enforced
        // by a policy rather than by the absence of a grant: the elevated role reads the PLATFORM ledger,
        // never tenant history — `audits_platform_select` carries `AND tenant_id IS NULL`.
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
