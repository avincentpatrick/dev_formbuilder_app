<?php

declare(strict_types=1);

use App\Support\Migrations\OcrCompatibilityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge-day backfill (Increment H8): clear `forms.capability_flags.ocr_compatible` on every already-published
 * form whose frozen structure the fixed `docs/ocr-pipeline-design.md` §2 rule rejects — a `matrix` /
 * `likert_matrix` field, a repeat group, or nothing extractable at all. This is what makes H8 retroactive by
 * construction rather than next-publish-only; without it a stale `true` would sit in a SHIPPED public API
 * field (`FormResource`) and in H18's eligibility gate until someone happened to republish.
 *
 * The full rationale — why this cannot run on the app connection, why the rule is computed in PHP instead of
 * restated in SQL, why the flip is monotone, why `updated_at` is untouched — lives in
 * {@see OcrCompatibilityBackfill}, which also makes it testable by invoking the body directly.
 *
 * No DDL: this is a data-only migration. On `migrate:fresh` the database is empty at this point, so nothing is
 * examined and the postcondition passes at zero. It only does real work against a live, populated database.
 *
 * ── Post-deploy verification (a HAND-RUN diagnostic — deliberately NOT shipped as executable code) ────
 * A committed, executable SQL restatement of §2 is precisely the drift this increment exists to prevent, so
 * the rule appears in SQL exactly once: here, in a comment, where the application cannot run it. Must return
 * zero rows, and the `NOT IN` list is `App\Enums\OcrFieldEligibility`'s non-Excluded set:
 *
 *   SELECT f.id, f.title
 *   FROM forms f JOIN form_versions v ON v.id = f.current_published_version_id
 *   WHERE f.capability_flags->>'ocr_compatible' = 'true'
 *     AND (EXISTS (SELECT 1 FROM jsonb_array_elements(v.schema_snapshot->'sections') s
 *                  WHERE COALESCE((s->>'is_repeatable')::bool, true))
 *       OR EXISTS (SELECT 1 FROM jsonb_array_elements(v.schema_snapshot->'fields') x
 *                  WHERE x->>'field_type' NOT IN ('short_text','long_text','email','phone','url','integer',
 *                        'decimal','date','time','datetime','duration','single_select','multi_select',
 *                        'dropdown','yes_no','cascading_select','likert_scale','note','page_break','hidden')));
 */
return new class extends Migration
{
    /**
     * The write goes to `pgsql_privileged` in AUTOCOMMIT, so the transaction Laravel opens on the DEFAULT
     * connection would roll back nothing here — leaving it on would only create the illusion of atomicity.
     * A thrown guard aborts the migration RUN, not the already-committed UPDATEs; recovery is fail-forward
     * and the run is idempotent (the candidate filter matches only a stored `true`, and the value written is
     * a constant, so a re-run examines strictly less and changes nothing).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $privileged = DB::connection('pgsql_privileged');

        // Asserted HERE rather than inside the body: the body is connection-agnostic by design (which is what
        // lets a Pest test prove its effect on real rows via the app connection), so "and this connection must
        // see every tenant" belongs at the point where the connection is chosen.
        OcrCompatibilityBackfill::assertPrivileged($privileged);

        (new OcrCompatibilityBackfill)($privileged);
    }

    /**
     * DELIBERATELY A NO-OP. The honest reverse of a correctness backfill is "restore the wrong value", which
     * is neither recoverable (which rows were flipped is not recorded) nor desirable — it would re-introduce
     * the exact regression H8 removes, handing H18 forms §2 cannot reconstruct.
     *
     * Nothing is lost by not reversing: `ocr_compatible` is a DERIVED value, recomputable at any time from the
     * published version's frozen `schema_snapshot` via `CapabilityFlags::forSnapshot()` — which is what up()
     * does. The pre-image is a bug, not data. A genuine revert is: revert the service, then republish.
     *
     * Contrast the two precedents, neither of which is analogous: G10a's down() restores ROWS it created and
     * H5c's TRUNCATEs a table it filled. This migration creates nothing — it mutates one key of a derived
     * jsonb. The up->down->up round trip is clean because there is no DDL to reverse and up() is idempotent.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock.
    }
};
