<?php

declare(strict_types=1);

use App\Support\Migrations\SubmissionReferenceBackfill;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `submissions.reference` (Increment J2e) — the short, quotable handle a respondent is shown and a support
 * agent pastes back into a search box. Eight Crockford Base32 characters, stored uppercase and ungrouped,
 * displayed `7K4M-2QXB`, unique per tenant.
 *
 * Why it exists at all, and why it is RANDOM rather than a slice of the id, lives in
 * {@see SubmissionReference} — short version: the product used to display `substr(id, 0, 8)` and accept it
 * back as a lookup, and J1e MEASURED that those eight characters are a uuidv7's timestamp prefix, identical
 * across a ~49-day window. It narrowed to a time window rather than to a row.
 *
 * ── The four phases, and why they are in this order ──────────────────────────────────────────────────────
 *  1. `ADD COLUMN reference varchar(8)` NULLABLE.
 *  2. `CREATE UNIQUE INDEX` on `(tenant_id, reference)` — WHILE IT IS STILL NULLABLE.
 *  3. Backfill every pre-existing row, on `pgsql_privileged`.
 *  4. `SET NOT NULL`.
 *
 * ⚠️ PHASE 2 SITS BEFORE PHASE 3 DELIBERATELY. PostgreSQL treats NULLs as distinct in a unique index, so
 * unlimited un-backfilled rows coexist under it — which means the backfill writes THROUGH the constraint and
 * the database is the only authority on uniqueness. The alternative (de-duplicate in a PHP set, then add the
 * index) puts a second authority beside the index, defers every failure to a `CREATE UNIQUE INDEX` that
 * explodes mid-migration, and — decisively — can only be tested probabilistically. See
 * {@see SubmissionReferenceBackfill} for the full argument.
 *
 * ── varchar(8), and stored WITHOUT the separator ─────────────────────────────────────────────────────────
 * `varchar` not `char`: `bpchar` ignores trailing spaces in comparison, so `'7K4M2QX '` would compare equal
 * to `'7K4M2QX'`, and it buys no storage in PostgreSQL. The hyphen is a DISPLAY affordance
 * ({@see SubmissionReference::format()}) and is not stored: storing `7K4M-2QXB` would make the column nine
 * wide, make every equality lookup depend on the grouping decision, and turn a future re-grouping into a
 * data migration.
 *
 * ⚠️ NO `WHERE deleted_at IS NULL` ARM ON THE INDEX, AND THAT IS A DECISION. A soft-deleted submission keeps
 * its reference reserved forever. Freeing it would let a code a respondent wrote down resolve LATER to a
 * DIFFERENT submission — strictly worse than "not found", and the worst possible property for a quotable
 * identifier. Consequence, recorded where it lands: `ReapTenantDraftsJob`'s `forceDelete()` now frees a
 * reference as well as a `client_submission_uuid`. That is correct — a reaped expired draft was never shown
 * a real reference (the offline guest path shows a provisional queue tag) — and the job's docblock says so,
 * because "fixing" it to a soft delete would silently reintroduce this.
 *
 * ⚠️ Also NOT the partial-index idiom `submissions_tenant_client_uuid_unique` uses, and the deviation is
 * deliberate rather than an oversight: that index carries `WHERE client_submission_uuid IS NOT NULL` because
 * its column stays nullable forever. `reference` is `NOT NULL` by phase 4, so there is no NULL population to
 * exempt and the partial predicate would have no job. Blueprint's generated name already matches the house
 * convention (cf. `forms_tenant_id_public_slug_unique`).
 *
 * ── No `withTenantIsolation()` re-emit ───────────────────────────────────────────────────────────────────
 * `submissions` already carries the strict RLS shape (`2026_07_09_000301:79`), and RLS policies are ROW
 * predicates rather than column lists — a new column is covered the moment it exists (the 2026_07_23_000006 /
 * 2026_08_09_000001 / 2026_08_11_000002 precedent).
 *
 * ⚠️ This migration creates no table, so `scripts/migration-lint.php` SKIPS it entirely and its green here is
 * vacuous, not evidence. Verified by running the linter, not assumed.
 *
 * ── Idempotency ──────────────────────────────────────────────────────────────────────────────────────────
 * `$withinTransaction = false` (phase 3 writes on a separate connection in autocommit, so a transaction on
 * the default connection would roll back nothing and only create the illusion of atomicity). A migration
 * that cannot roll back must be re-runnable instead, so every DDL statement carries `IF [NOT] EXISTS` and
 * the backfill's own predicate (`reference IS NULL`) matches strictly less on each pass. Recovery is
 * fail-forward: run it again.
 *
 * On `migrate:fresh` the table is empty at phase 3, so the backfill examines nothing and its postcondition
 * passes at zero. It only does real work against a live, populated database.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Phase 1. Raw rather than Blueprint for the `IF NOT EXISTS` re-runnability described above.
        // Positioned after `client_submission_uuid` in spirit only: PostgresGrammar has no `modifyAfter`, so
        // Laravel discards `->after()` silently (the 2026_08_09_000001:80-83 note) and the column lands last.
        DB::statement('ALTER TABLE submissions ADD COLUMN IF NOT EXISTS reference varchar(8)');

        // Phase 2 — before the backfill. See the class docblock.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS submissions_tenant_id_reference_unique '
            .'ON submissions (tenant_id, reference)'
        );

        // Phase 3.
        $privileged = DB::connection('pgsql_privileged');

        // Asserted HERE rather than inside the body: the body is connection-agnostic by design (which is what
        // lets a Pest test prove its effect on real rows via the app connection), so "and this connection must
        // see every tenant" belongs at the point where the connection is chosen.
        SubmissionReferenceBackfill::assertPrivileged($privileged);

        (new SubmissionReferenceBackfill)($privileged);

        // Phase 4. Safe only because phase 3's postcondition has already refused to reach this line while any
        // row is still NULL.
        DB::statement('ALTER TABLE submissions ALTER COLUMN reference SET NOT NULL');
    }

    /**
     * Reverses phases 4, 2 and 1. Phase 3 has no reverse and needs none — dropping the column discards every
     * value it wrote, so there is no pre-image to restore.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_tenant_id_reference_unique');
        DB::statement('ALTER TABLE submissions DROP COLUMN IF EXISTS reference');
    }
};
