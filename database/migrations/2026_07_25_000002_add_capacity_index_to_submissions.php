<?php

declare(strict_types=1);

use App\Models\Submission;
use App\Services\Submissions\FormAcceptanceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment H12a — a partial index supporting the `max_responses` capacity guard ({@see FormAcceptanceGuard}),
 * which per finalize runs a `SELECT count(*) FROM submissions WHERE form_id = ? AND …` under a form-row lock.
 * Indexing `(tenant_id, form_id)` filtered to finalized rows keeps the cap COUNT an index scan and stays small
 * because in-flight drafts (the bulk of churn) never qualify.
 *
 * ⚠️ THE INDEX PREDICATE IS DELIBERATELY WIDER THAN THE GUARD'S QUERY SINCE I9a, AND MUST STAY THAT WAY.
 * The guard's predicate narrowed to `status <> 'draft' AND status <> 'screened_out'`
 * ({@see Submission::scopeConsumesCapacity()}) — a strict SUBSET of this index's rows, so Postgres
 * can still prove the index predicate from the query and the extra conjunct is rechecked on the scan.
 * This index was NOT narrowed to match, because it has a SECOND consumer: H24a's per-form analytics series,
 * which uses `scopeCountable()` (`status <> 'draft'`) and would lose the index entirely. Narrowing it would be
 * a silent performance regression on the analytics path that no correctness test can see.
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
