<?php

declare(strict_types=1);

use App\Services\Submissions\FormAcceptanceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment H12a — a partial index supporting the `max_responses` capacity guard ({@see FormAcceptanceGuard}),
 * which per finalize runs `SELECT count(*) FROM submissions WHERE form_id = ? AND status <> 'draft'` under a
 * form-row lock. Indexing `(tenant_id, form_id)` filtered to finalized rows keeps the cap COUNT an index scan
 * and stays small because in-flight drafts (the bulk of churn) never qualify.
 *
 * Alter-only (a raw `CREATE INDEX`, no table create) — no `withTenantIsolation()` re-emit is needed and the
 * migration linter skips it, exactly like 2026_07_23_000007_add_draft_expiry_index_to_submissions. The partial
 * predicate is not expressible via Blueprint's `->index()`, so it uses the same raw-DDL idiom.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX submissions_form_finalized_idx '
            .'ON submissions (tenant_id, form_id) '
            ."WHERE status <> 'draft'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_form_finalized_idx');
    }
};
