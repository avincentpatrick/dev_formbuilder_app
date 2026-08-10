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
 * ── ⚠️ THERE IS NO GIN INDEX HERE EITHER, AND THIS TABLE HAS **TWO** INDEPENDENT REASONS ─────────────────
 * Read 2026_08_11_000001's "THERE IS NO GIN INDEX" section first — the RLS/leakproof mechanism it measures
 * applies to `submissions` unchanged, since this table is ENABLE'd and FORCE'd exactly the same way.
 *
 * **The second reason is not about RLS at all, and it would survive fixing the first.** `SubmissionSearchArm`
 * matches through a three-branch OR group: `search_vector @@ ...` OR `form_id IN (<subquery over forms>)` OR,
 * for a reference lookup, `id::text LIKE ...`. `generate_bitmap_or_paths()` abandons an ENTIRE OR clause the
 * moment one branch yields no index path:
 *
 *     // If we fail to generate any paths for this arm, we can't do anything with this OR clause.
 *     if (indlist == NIL) { pathlist = NIL; break; }
 *
 * An `ANY` SubLink nested inside an OR is never pulled up into a semijoin, so the middle branch stays a
 * `SubPlan`, and a SubPlan-bearing clause can never be an index qual. Measured, sequential scans penalised so
 * the shape is not a small-table artefact — note `hashed SubPlan 1` sitting in the Filter:
 *
 *   Index Scan using submissions_form_finalized_idx on submissions
 *     Index Cond: (tenant_id = ...)
 *     Filter: ((deleted_at IS NULL) AND ((search_vector @@ ...) OR (ANY (form_id = (hashed SubPlan 1).col1))))
 *
 * So a future author who rescues the RLS half — by a `LEAKPROOF` assertion or otherwise — must NOT expect an
 * index here to come back. Restoring it needs the OR turned into a UNION of separately-indexable branches,
 * which changes this arm's ranking and limit semantics and is deliberately not J1b's work.
 *
 * ── THE PARTIAL PREDICATE ARGUMENT, KEPT BECAUSE IT GOVERNS WHATEVER IS BUILT NEXT ───────────────────────
 * When an index does return here it must be `deleted_at IS NULL` ONLY, and deliberately NOT
 * `AND status <> 'draft'`, even though the arm applies `->countable()` and the inbox hides drafts by default.
 * Coupling an index to a DISPLAY DEFAULT means a later change to `Submission::scopeCountable()` silently loses
 * it — the query keeps returning correct rows and simply gets slower, which is the failure mode no functional
 * test can see. Soft-deletion is a different kind of fact: the SoftDeletes global scope emits
 * `deleted_at IS NULL` on every `Submission::query()`, so it is provable from any builder.
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
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE submissions DROP COLUMN IF EXISTS search_vector');
    }
};
