<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment J1b — the full-text substrate for the forms arm of global search (PRD §3.7, "global search is
 * non-negotiable"). Before this the product had NO search infrastructure of any kind: a repo-wide grep for
 * `tsvector`, `to_tsquery`, `USING GIN`, `pg_trgm` and Scout returns nothing, and not one list page carries a
 * text filter.
 *
 * ── WHY A GENERATED COLUMN ON `forms` AND NOT A `search_documents` PROJECTION TABLE ───────────────────────
 * Three arguments, any one of them decisive here.
 *
 * 1. **It keeps the visibility rule in one place.** RLS gives tenant isolation and nothing else, so every
 *    search arm needs a SECOND predicate — and those predicates are already expressed as builders over the
 *    entity's own table (`ResourceGrantResolver::grantedFormIdsQuery()` returns a `Builder<Form>`). Keeping
 *    the vector ON `forms` means `Form::scopeVisibleTo()` composes onto the same query unchanged. A
 *    denormalised table cannot reuse any of it and would have to re-derive the rule — and this repo has
 *    already paid for that: form visibility is currently encoded TWICE, differently (see the scope's
 *    docblock), and a third copy inside a search service is exactly how the fourth one gets written.
 * 2. **It is derived data, not a copy.** `GENERATED ALWAYS ... STORED` recomputes on every UPDATE of its
 *    source columns, so it cannot drift and it needs no writer, no backfill and no `TenantAwareJob`. A
 *    projection table means a writer on four paths, each one a silent-failure surface: a missed writer is a
 *    form that exists and cannot be found, with nothing red anywhere.
 * 3. **RLS comes free.** The column lives on an already-FORCE-RLS table, so there is no new policy, no new
 *    `pg_policies` sweep row, and no new way to widen the blast radius. A new table carrying `tenant_id`
 *    would also have to answer to `scripts/migration-lint.php`.
 *
 * ── WHY THE `'simple'` REGCONFIG, NOT `'english'` ────────────────────────────────────────────────────────
 * `to_tsvector('english', ...)` is legal here (with an EXPLICIT regconfig it is IMMUTABLE; the single-argument
 * form is not, and would be rejected outright by the generated-column parser). It is still wrong for this
 * corpus:
 *   - the corpus is multilingual by construction — `form_fields.label_translations`, `tenants.default_locale`,
 *     `submissions.locale`, PSGC-shaped scope trees — and English stemming over Filipino or Cebuano produces
 *     junk lexemes;
 *   - `'english'` strips stopwords, so a form titled "The A Team" indexes to approximately nothing and becomes
 *     unfindable by its own title;
 *   - `'simple'` stores literal lowercased lexemes, which is what makes the `:*` prefix match honest for a
 *     type-ahead surface.
 *
 * ⚠️ DO NOT "FIX" THIS INTO A PER-ROW REGCONFIG. `to_tsvector(default_locale::regconfig, title)` is technically
 * immutable and technically legal in a generated column, and it is a live footgun: both `'fil'::regconfig` and
 * `'en-PH'::regconfig` RAISE, so the first form created under a non-English locale fails to INSERT.
 *
 * The stated cost, so nobody reports it as a bug: there is no stemming, so "submissions" does not match
 * "submission" except through the prefix arm. Real stemming, plus `unaccent`, needs a privileged extension
 * migration and is deferred.
 *
 * ── WEIGHTS ──────────────────────────────────────────────────────────────────────────────────────────────
 * Title A, description B, slug C, so `ts_rank` is meaningful without a second ORDER BY heuristic bolted on
 * top. The slug is de-hyphenated because `clinic-intake` should be findable by "intake"; it is weighted last
 * because it is a URL fragment, not prose.
 *
 * ── THE INDEX, AND ONE OMISSION STATED RATHER THAN HIDDEN ─────────────────────────────────────────────────
 * A plain `USING GIN (search_vector)`. A multicolumn `GIN (tenant_id, search_vector)` — which would let one
 * index serve the RLS tenant predicate and the match together — requires the `btree_gin` extension, and only
 * `postgis` is enabled in this deployment. Postgres bitmap-ANDs the GIN result against the tenant predicate
 * served by the existing `(tenant_id, ...)` B-trees, which is correct and adequate at Phase-1 volumes on the
 * single box of ADR-0005. If search latency ever shows up against `docs/non-functional-requirements.md` §1,
 * `CREATE EXTENSION btree_gin` is a one-line privileged migration mirroring 2026_07_12_000001 and is the first
 * thing to reach for. It is deliberately NOT added speculatively: ADR-0001:56 already claims three extensions
 * that are not actually enabled, and a fourth claimed-but-unused one makes that record worse.
 *
 * `WHERE deleted_at IS NULL` is provable from any `Form::query()` because the SoftDeletes global scope emits
 * exactly that predicate — the argument 2026_08_03_000001 makes for its own partial indexes.
 *
 * ── LOCKS ────────────────────────────────────────────────────────────────────────────────────────────────
 * `ADD COLUMN ... GENERATED ... STORED` takes ACCESS EXCLUSIVE and REWRITES THE TABLE — heavier than the SHARE
 * lock 2026_08_03_000001 records, and unlike a `CREATE INDEX` it cannot be made concurrent at all. Defensible
 * for exactly the same reason: Track B is deferred and no production host exists. A live re-run would need a
 * plain column plus a `BEFORE INSERT OR UPDATE` trigger plus a batched backfill instead — recorded here so the
 * next author does not have to rediscover it, not built, because building it now would be speculative.
 *
 * Alter-only (no `Schema::create`, no literal `tenant_id` column), so `scripts/migration-lint.php` skips it —
 * the same idiom as 2026_08_03_000001. Verified by running the linter, not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE forms ADD COLUMN search_vector tsvector GENERATED ALWAYS AS ('
            ."setweight(to_tsvector('simple', coalesce(title, '')), 'A') || "
            ."setweight(to_tsvector('simple', coalesce(description, '')), 'B') || "
            ."setweight(to_tsvector('simple', coalesce(replace(public_slug, '-', ' '), '')), 'C')"
            .') STORED'
        );

        DB::statement(
            'CREATE INDEX forms_search_vector_idx ON forms USING GIN (search_vector) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // Index first. Dropping the column would cascade to it anyway, but an explicit order keeps the
        // rollback readable and makes a partial failure diagnosable.
        DB::statement('DROP INDEX IF EXISTS forms_search_vector_idx');
        DB::statement('ALTER TABLE forms DROP COLUMN IF EXISTS search_vector');
    }
};
