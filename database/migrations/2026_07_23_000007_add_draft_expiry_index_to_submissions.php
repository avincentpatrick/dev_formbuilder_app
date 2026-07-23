<?php

declare(strict_types=1);

use App\Jobs\Submissions\ReapTenantDraftsJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment H9b — a partial index supporting the draft-expiry reaper ({@see ReapTenantDraftsJob}),
 * which per tenant runs `DELETE FROM submissions WHERE status = 'draft' AND draft_expires_at < now()` nightly.
 * Indexing `(tenant_id, draft_expires_at)` filtered to drafts turns that per-tenant sweep into an index scan
 * rather than a full table scan, and stays small because only un-promoted rows qualify (promotion nulls
 * `draft_expires_at`, so a promoted/submitted row is never in the index).
 *
 * Alter-only (a raw `CREATE INDEX`, no table create) — no `withTenantIsolation()` re-emit is needed and the
 * migration linter skips it, exactly like 2026_07_23_000006_add_draft_columns_to_submissions. The partial
 * predicate is not expressible via Blueprint's `->index()`, so it uses the same raw-DDL idiom as the
 * `submissions_tenant_client_uuid_unique` index in 2026_07_09_000301_create_submissions_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX submissions_draft_expiry_idx '
            .'ON submissions (tenant_id, draft_expires_at) '
            ."WHERE status = 'draft'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_draft_expiry_idx');
    }
};
