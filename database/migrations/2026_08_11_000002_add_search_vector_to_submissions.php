<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment J1b — the full-text substrate for the submissions arm of global search. Read
 * 2026_08_11_000001's docblock first: the generated-column-over-projection-table argument, the `'simple'`
 * regconfig reasoning, the `btree_gin` omission and the ACCESS EXCLUSIVE lock note all apply here unchanged.
 *
 * ⚠️ WHAT IS DELIBERATELY *NOT* IN THIS VECTOR, AND WHY — THIS IS THE IMPORTANT HALF OF THE FILE.
 *
 * ── ANSWER TEXT IS EXCLUDED (user decision, 2026-08-09) ───────────────────────────────────────────────────
 * A tenant cannot search the answers people typed. Four independent reasons, any one sufficient:
 *
 * 1. **It would be a second store of PII with nothing able to scrub it.** `submissions.pii_erased_at` exists
 *    as a column and has ZERO writers anywhere in `app/` — GDPR erasure is simply not implemented yet.
 *    Whoever builds it will scrub `submissions.guest_*` and `submission_answers.answers`; a search projection
 *    they never knew about is precisely the store that survives an erasure request. Building the second store
 *    BEFORE the first erasure path exists inverts the safe order.
 * 2. **The typed index is empty in practice.** `submission_answer_index.value_text` is populated only for
 *    fields flagged `is_queryable`, and that flag defaults false in the migration, in `FormBuilderService`, in
 *    every seeder, is hard-coded false by `FieldLibrary::toBlueprintField()`, and was never backfilled. A
 *    search built on it would ship a feature that returns nothing for essentially every form alive.
 * 3. **The raw document has no index and would poison ranking.** `submission_answers.answers` is jsonb with a
 *    single `(tenant_id, form_version_id)` B-tree; `jsonb_to_tsvector` over it would tokenise option codes,
 *    repeat-group scaffolding keys and the strings "true"/"false" indiscriminately.
 * 4. **An answer's visibility is not a submission's visibility.** `form_fields.is_pii` / `is_sensitive` exist
 *    precisely to mark answers that must not be splashed around, and a full-text hit is only useful with a
 *    snippet — which is a NEW disclosure with no policy behind it.
 *
 * `SubmissionSearchArm` matches the form's title instead, which is the only label the inbox has ever shown,
 * and `SearchTerms::uuidPrefix()` covers "I have the reference from an email".
 *
 * ── PII COLUMNS ARE EXCLUDED ─────────────────────────────────────────────────────────────────────────────
 * `guest_contact_email`, `guest_ip` and `guest_user_agent` are all on `AuditRedactor::PII` — the list whose
 * whole purpose is that this data never lands in a second table. `locale` is not text a human searches.
 * `remarks` (B) and `returned_reason` (C) are in, and both are already rendered on the detail page to anyone
 * who can `view` the submission, so the vector discloses nothing new.
 *
 * ── THE PARTIAL PREDICATE IS `deleted_at IS NULL` ONLY ───────────────────────────────────────────────────
 * Deliberately NOT `AND status <> 'draft'`, even though the arm applies `->countable()` and the inbox hides
 * drafts by default. Coupling an index to a DISPLAY DEFAULT means a later change to
 * `Submission::scopeCountable()` silently loses the index — the query keeps returning correct rows and simply
 * gets slower, which is the failure mode no test can see. Soft-deletion is a different kind of fact: the
 * SoftDeletes global scope emits `deleted_at IS NULL` on every `Submission::query()`, so it is provable.
 *
 * Alter-only, so `scripts/migration-lint.php` skips it. Verified by running the linter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE submissions ADD COLUMN search_vector tsvector GENERATED ALWAYS AS ('
            ."setweight(to_tsvector('simple', coalesce(remarks, '')), 'B') || "
            ."setweight(to_tsvector('simple', coalesce(returned_reason, '')), 'C')"
            .') STORED'
        );

        DB::statement(
            'CREATE INDEX submissions_search_vector_idx ON submissions USING GIN (search_vector) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_search_vector_idx');
        DB::statement('ALTER TABLE submissions DROP COLUMN IF EXISTS search_vector');
    }
};
