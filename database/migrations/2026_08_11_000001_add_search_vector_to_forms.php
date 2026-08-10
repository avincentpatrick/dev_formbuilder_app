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
 * ── ⚠️ THERE IS NO GIN INDEX ON THIS COLUMN, AND THAT IS A MEASUREMENT, NOT AN OVERSIGHT ─────────────────
 * The first draft of this migration shipped `CREATE INDEX forms_search_vector_idx ON forms USING GIN
 * (search_vector) WHERE deleted_at IS NULL` and argued, in this docblock, that "Postgres bitmap-ANDs the GIN
 * result against the tenant predicate served by the existing `(tenant_id, ...)` B-trees". **That was false,
 * and every functional test passed over it** — identical rows come back either way; only the plan differs.
 *
 * **PostgreSQL will not let a non-leakproof clause become an index qual on a relation carrying RLS quals.**
 * `match_clause_to_index()` opens by rejecting any clause that fails
 * `restriction_is_securely_promotable()`, which passes only when the clause's `security_level` is at
 * `rel->baserestrict_min_security` or the clause is leakproof. An RLS policy qual sits *below* every
 * ORM-emitted `WHERE`, so on this FORCE-RLS table the search predicate is always at the higher level — and
 * `@@` is not leakproof. Measured on PG 17.0.5 rather than argued:
 *
 *   SELECT p.proleakproof FROM pg_operator o JOIN pg_proc p ON p.oid = o.oprcode
 *   WHERE o.oprname = '@@' AND o.oprleft = 'tsvector'::regtype AND o.oprright = 'tsquery'::regtype;
 *   -- ts_match_vq(tsvector,tsquery) | proleakproof = f
 *
 * The controlled experiment, one variable, same rows and same session (it is `SearchIndexUsageTest`'s
 * `rls_probe` case, so it runs on every suite): a 5,000-row table with a GIN on its tsvector plans as
 * `Bitmap Index Scan`; ENABLE + FORCE one tenant policy on it and the identical query becomes a `Seq Scan`
 * with the match demoted to a `Filter`. **Penalising sequential scans to a cost of 10^10 does not bring the
 * index back**, which is what proves this is eligibility and not the cost model.
 *
 * `btree_gin` would not have rescued it either — the blocker is clause eligibility, not index shape — so the
 * "reach for `CREATE EXTENSION btree_gin` first" advice this docblock used to carry has been removed rather
 * than left to send the next author down a dead end.
 *
 * ── WHAT BOUNDS THE WORK INSTEAD, WHICH IS THE PROPERTY WORTH DEFENDING ───────────────────────────────────
 * The RLS tenant qual *is* securely promotable (it sits at the minimum security level by construction), so it
 * becomes the index condition and the search predicate is applied as a heap filter over one tenant's rows.
 * Measured, again with sequential scans penalised so the shape is not a small-table artefact:
 *
 *   Index Scan using forms_tenant_id_id_unique on forms
 *     Index Cond: (tenant_id = ...)
 *     Filter: ((deleted_at IS NULL) AND (status <> 'archived') AND (search_vector @@ '''clin'':*'::tsquery))
 *
 * That is O(this tenant's forms), which is microseconds at Phase-1 volumes on the single box of ADR-0005 and
 * stops being adequate somewhere around 10^5–10^6 forms in ONE tenant. The honest options at that point are a
 * lexeme side-table keyed `(tenant_id, form_id, lexeme COLLATE "C")` — whose btree operators ARE leakproof
 * (measured: `texteq`/`text_lt`/`text_ge` are all `proleakproof = t`), so a range-scanned prefix survives RLS
 * where `@@` cannot — or a deliberate, user-approved `ALTER FUNCTION ... LEAKPROOF`. Note that `pg_trgm` is
 * NOT an escape hatch: `~~` and `~~*` measure `proleakproof = f`, so it hits this same wall.
 * Neither is built, because building either now would be speculative. The column stays because the `@@`
 * filter and `ts_rank` ordering both still need it; only the unreachable index is gone.
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
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP COLUMN IF EXISTS search_vector');
    }
};
