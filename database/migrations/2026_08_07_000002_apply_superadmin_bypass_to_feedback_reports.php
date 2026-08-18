<?php

declare(strict_types=1);

use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The super-admin carve-out on `feedback_reports` (Increment I7a, RBAC §9 console scope, ADR-0002 §D3) —
 * the third bypass in the deployment and, like the other two, a decision already taken in writing.
 * `2026_07_06_000100_create_feedback_reports_table.php`'s docblock defers "the cross-tenant platform
 * support console … via the elevated super-admin carve-out" to the increment that builds the UI, and
 * data-dictionary §21's Design Notes name the same mechanism: the support team reads across tenants
 * through "the same elevated-role carve-out already specified in ADR-0002 §D3 … not a second, parallel
 * bypass mechanism invented for this table".
 *
 * ── SELECT ONLY, AND THAT IS THE INTERESTING PART ───────────────────────────────────────────────────────
 * The console does two things: it LISTS reports across every tenant, and it TRANSITIONS one report's
 * status. Only the first is genuinely cross-tenant. A transition targets exactly one report whose
 * `tenant_id` the console has already read, so it is written through the H4 adopt-tenant-context pattern
 * ({@see SuperAdminService::changeStatus()} / `assignPlan()`): one transaction, the
 * affected tenant's context adopted, the UPDATE and its audit row both passing the ORDINARY strict
 * policies on the ordinary connection, context restored in `finally`.
 *
 * That is not a stylistic preference. `SuperAdminService`'s own header states the rule — RBAC §9 requires
 * "route through the one service", NOT "elevate every op" — and an UPDATE bypass here would buy three
 * costs for nothing: a second write path to `feedback_reports` that RLS no longer constrains, an audit row
 * that would have to be written on the elevated connection through the `audits` INSERT bypass (and would
 * then carry `tenant_id = NULL`, making it INVISIBLE in the reporting tenant's own /audit-log — the exact
 * opposite of the RBAC §9 transparency requirement that a tenant can see what the operator did to them),
 * and a wider blast radius on a table holding user-authored PII.
 *
 * **So: do not add INSERT/UPDATE/DELETE to this bypass later without re-reading the above.** If a future
 * console operation genuinely cannot name its tenant, that is the argument to make — not "for symmetry".
 *
 * ── THE GRANT IS NOT DECORATION ─────────────────────────────────────────────────────────────────────────
 * Table privileges are checked BEFORE RLS: without the GRANT the query fails "permission denied" before
 * any policy is consulted, and a test meant to prove the POLICY would pass for the wrong reason.
 * `FeedbackRlsTest` asserts the two halves separately, and additionally asserts the negative that matters
 * most — the policy is scoped `TO meridian_superadmin` and gated on a GUC, so an ordinary tenant
 * connection is completely unaffected by this file and still cannot read another tenant's reports.
 *
 * Runs on the default `meridian_app` connection: it owns `feedback_reports`, so it is who can GRANT +
 * CREATE POLICY. Creates no table, so the migration linter does not require withTenantIsolation() here.
 *
 * @see SuperAdminContext::applyLocal() — the only thing that opens `app.is_superadmin_context`
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        DB::statement("GRANT SELECT ON feedback_reports TO {$role}");

        TenantIsolation::applySuperAdminBypass('feedback_reports', ['SELECT']);
    }

    public function down(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'feedback_reports' AND policyname LIKE 'feedback_reports_superadmin_%'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON feedback_reports');
        }

        DB::statement("REVOKE SELECT ON feedback_reports FROM {$role}");
    }
};
