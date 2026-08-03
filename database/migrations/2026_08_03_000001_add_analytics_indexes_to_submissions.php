<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment H24a — the ONE index addendum ADR-0011 §D7 assigns, and the reason it is singular even though two
 * statements appear below: the capacity index is WIDENED in place, so the net index count on `submissions` is
 * unchanged.
 *
 * Context 9 of the ADR records the gap this closes: `submissions` carries `(tenant_id, form_id)`,
 * `(tenant_id, form_version_id)` and `(tenant_id, status)` and **no time-ordered index at all**, so every
 * date-bucketed aggregate is a filtered scan under the RLS predicate — against the `< 400ms` target /
 * `< 1.5s` maximum of `docs/non-functional-requirements.md` §1, on the single box of ADR-0005, in the exact
 * area Risk R9 is still open about.
 *
 * ── Why the predicate carries `deleted_at IS NULL` ────────────────────────────────────────────────────────
 * Not a micro-optimisation. Without it every candidate tuple needs a heap visit to test `deleted_at`, which
 * destroys the index-only scan that is the whole performance argument. It is provable from any
 * `Submission::query()` because the SoftDeletes global scope emits exactly that predicate — which is also why
 * `Submission::applyCountableJoin()` spells it out on the join side.
 *
 * **Corollary, and a hard rule for the analytics namespace: no analytics query may use `withTrashed()` on
 * `submissions`.** Doing so makes `deleted_at IS NULL` unprovable and silently loses this index.
 *
 * ── Why `INCLUDE (form_id)` rather than a third key column ────────────────────────────────────────────────
 * After a range qual a third key column is unusable as a search key and only widens the B-tree key. As an
 * INCLUDE payload (PG 11+; we are on postgis/postgis:17-3.5) it makes "top forms by submission volume in
 * range" — an explicit `docs/PRD.md:197` deliverable — an index-only GROUP BY.
 *
 * ── Why the capacity index is widened ─────────────────────────────────────────────────────────────────────
 * `(tenant_id, form_id) WHERE status <> 'draft'` (2026_07_25_000002, H12a) cannot serve a time range at all,
 * so the PRD's PER-FORM series would scan the whole tenant. `(tenant_id, form_id, submitted_at)` is a strict
 * prefix-superset, so `FormAcceptanceGuard::assertCapacity()`'s hot-path COUNT is unaffected.
 *
 * ── Two caveats, stated rather than left silent ───────────────────────────────────────────────────────────
 * 1. Index-only scans require a set visibility map. On a write-heavy `submissions` table with default
 *    autovacuum they degrade to heap fetches, so the honest claim is "the index BOUNDS the row set";
 *    index-only is a bonus, not a guarantee.
 * 2. Plain `CREATE INDEX` takes a SHARE lock and blocks INSERT on `submissions` for its duration.
 *    `CONCURRENTLY` cannot run inside a transaction and would need `public $withinTransaction = false`.
 *    Plain is defensible ONLY because Track B is deferred and no production host exists yet; a future
 *    re-index against live data should reconsider.
 *
 * Alter-only (raw `CREATE INDEX`, no table create), so the migration linter skips it — the same idiom and the
 * same reasoning as 2026_07_23_000007 and 2026_07_25_000002. The partial predicate and the INCLUDE clause are
 * both inexpressible via Blueprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX submissions_analytics_series_idx '
            .'ON submissions (tenant_id, submitted_at) INCLUDE (form_id) '
            ."WHERE status <> 'draft' AND deleted_at IS NULL"
        );

        DB::statement('DROP INDEX IF EXISTS submissions_form_finalized_idx');
        DB::statement(
            'CREATE INDEX submissions_form_finalized_idx '
            .'ON submissions (tenant_id, form_id, submitted_at) '
            ."WHERE status <> 'draft'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_analytics_series_idx');

        // Restore H12a's narrower shape rather than leaving the widened one behind.
        DB::statement('DROP INDEX IF EXISTS submissions_form_finalized_idx');
        DB::statement(
            'CREATE INDEX submissions_form_finalized_idx '
            .'ON submissions (tenant_id, form_id) '
            ."WHERE status <> 'draft'"
        );
    }
};
