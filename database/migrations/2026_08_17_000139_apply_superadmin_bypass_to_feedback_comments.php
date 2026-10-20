<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform support console's SELECT-ONLY carve-out on `feedback_comments` (M179, `R-129c44c3`) — the
 * reports' own arrangement (`2026_08_07_000002_apply_superadmin_bypass_to_feedback_reports.php` holds the
 * reasoning in full), so the console and the support API can show every workspace's threads.
 *
 * ⛔ DO NOT ADD INSERT/UPDATE/DELETE TO THIS BYPASS LATER. A support reply is written the way a support
 * transition is: by ADOPTING the report's tenant context (`SuperAdminService`), so it passes the ordinary strict
 * policy and its audit row lands in that workspace's own ledger. A write bypass would let the console write
 * outside RLS with an audit row whose `tenant_id` is NULL — invisible to the very workspace it concerns.
 * `FeedbackCommentRlsTest` pins exactly one policy, and SELECT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        DB::statement("GRANT SELECT ON feedback_comments TO {$role}");

        TenantIsolation::applySuperAdminBypass('feedback_comments', ['SELECT']);
    }

    public function down(): void
    {
        $role = (string) config('database.connections.pgsql_superadmin.username', 'meridian_superadmin');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_superadmin role name: {$role}");
        }

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'feedback_comments' AND policyname LIKE 'feedback_comments_superadmin_%'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON feedback_comments');
        }

        DB::statement("REVOKE SELECT ON feedback_comments FROM {$role}");
    }
};
