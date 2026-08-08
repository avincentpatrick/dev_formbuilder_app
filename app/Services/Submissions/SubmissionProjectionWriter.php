<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswerIndex;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The derived query projections of a submission's answers — the typed scalar `submission_answer_index` rows
 * and the PostGIS `submission_geo_index` geometries (data-dictionary §9 for the scalar index; ADR-0006 D1
 * for the geometry table, which the data dictionary does not cover at all — an earlier draft cited
 * '§8/§9' for both, which would send a maintainer to `submission_answers`). Extracted from
 * {@see SubmissionFinalizer} in Increment I9c so the two callers that now need them share one implementation:
 *
 *   - {@see SubmissionFinalizer::finalize()} projects ONCE, for a row that has no projections yet → {@see write()}.
 *   - {@see SubmissionAnswerEditService::edit()} REPLACES the projections of a row that already has them
 *     → {@see rewrite()}.
 *
 * The JSONB answer document remains the source of truth; both tables are derived and disposable, which is
 * precisely what makes delete-then-insert the correct edit strategy rather than a diff.
 *
 * ── ⚠️ THESE WERE INSERT-ONLY UNTIL I9c, AND NOTHING SAID SO ────────────────────────────────────────────
 * Before this class there was no DELETE against either table anywhere in `app/` — not a policy decision, just
 * the shape a write path acquires when the only caller creates rows that never existed before. An edit that
 * reused the old inline projectors would have APPENDED a second row per changed answer: the JSONB would say
 * one thing, `submission_answer_index` would carry both the old and the new value, and every filter, export
 * column and analytics series reading the index would double-count the edited submission. Nothing would
 * error, and — this is the part that matters for the tests — **every assertion that checks a projected VALUE
 * still passes**, because the new row is there. Only a COUNT assertion fails. `SubmissionProjectionWriterTest`
 * asserts counts for that reason, and the class docblock says so here so a future maintainer adding a third
 * caller knows which of the two methods they want.
 *
 * ── ⚠️ THE DELETE IS RLS-SCOPED, NOT APPLICATION-SCOPED, AND THAT IS LOAD-BEARING ────────────────────────
 * Both tables carry {@see TenantIsolation::strictSql()}'s shape, whose `tenant_delete`
 * policy is `USING (tenant_id = current_setting('app.current_tenant_id'))` under FORCE ROW LEVEL SECURITY. So
 * a DELETE keyed on `submission_id` alone cannot reach another tenant's rows even if a submission id were
 * guessed or leaked — the database refuses it, not this class. (Contrast `audits`, which is append-only and
 * has NO delete policy at all: the same idiom there would fail silently by deleting nothing. Do not copy this
 * pattern to a table without first checking which shape its migration applied.)
 */
final class SubmissionProjectionWriter
{
    public function __construct(
        private readonly AnswerIndexProjector $projector,
        private readonly GeoIndexProjector $geoProjector,
    ) {}

    /**
     * Project a submission's answers for the first time. The two private methods {@see SubmissionFinalizer} carried inline before
     * I9c, moved with their BODIES byte-identical — the extraction is a move, not a change. (Their
     * docblocks were adjusted where they said 'this transaction' and now say 'the caller's', so diff the
     * prose too rather than trusting 'byte-for-byte', which an earlier draft of this line claimed.)
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $effectiveAnswers
     */
    public function write(Submission $submission, FormVersion $version, Collection $fields, array $effectiveAnswers): void
    {
        $this->projectIndex($submission, $version, $fields, $effectiveAnswers);
        $this->projectGeo($submission, $version, $fields, $effectiveAnswers);
    }

    /**
     * Replace a submission's projections wholesale (I9c's answer edit).
     *
     * DELETE-then-INSERT rather than a per-row diff, deliberately: the projections are a pure function of
     * (version, fields, answers) with no identity of their own and nothing referencing them, so a diff would
     * be more code, more branches and more ways to leave a stale row behind, in exchange for saving a handful
     * of writes on a row that is edited by hand. Both statements run inside the CALLER's transaction — see
     * {@see SubmissionAnswerEditService::edit()} — so a failure between them cannot leave a submission with
     * no projections at all.
     *
     * ⚠️ THE VERSION IS THE SUBMISSION'S OWN PIN, not the form's currently-published one; the caller resolves
     * it. Re-projecting against a newer version would write `form_version_id` values that disagree with the
     * head row and the answer document.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $effectiveAnswers
     */
    public function rewrite(Submission $submission, FormVersion $version, Collection $fields, array $effectiveAnswers): void
    {
        $this->purge($submission);
        $this->write($submission, $version, $fields, $effectiveAnswers);
    }

    /**
     * Drop every derived row for this submission.
     *
     * The scalar side goes through Eloquent (so any future model-level concern applies) and the geo side
     * through bound raw SQL, mirroring how each is WRITTEN — `submission_geo_index` is inserted raw because
     * its geometry needs `ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)`, which Eloquent cannot express, and
     * keeping the delete on the same footing means one mental model per table rather than two.
     *
     * `tenant_id` is not in either WHERE clause on purpose: RLS supplies it (see the class docblock), and
     * spelling it a second time in the application would create a second answer to "what scopes this",
     * which is exactly the divergence ADR-0002 keeps policies generated to avoid.
     */
    private function purge(Submission $submission): void
    {
        SubmissionAnswerIndex::query()->where('submission_id', $submission->id)->delete();

        DB::delete('DELETE FROM submission_geo_index WHERE submission_id = ?', [$submission->id]);
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $effectiveAnswers
     */
    private function projectIndex(Submission $submission, FormVersion $version, Collection $fields, array $effectiveAnswers): void
    {
        /** @var array<string, FormField> $byKey */
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        foreach ($effectiveAnswers as $key => $value) {
            // Repeat-group instance arrays (keyed by section key) and multi-select lists are never indexed —
            // only scalar field answers reach the typed index (data-dictionary §8/§9).
            if (is_array($value)) {
                continue;
            }

            $field = $byKey[$key] ?? null;
            if ($field === null) {
                continue;
            }

            $projection = $this->projector->project($field, $value);
            if ($projection === null) {
                continue;
            }

            SubmissionAnswerIndex::create([
                'submission_id' => $submission->id,
                'form_version_id' => $version->id,
                'form_field_id' => $field->id,
                'field_key' => $field->key,
                $projection['column'] => $projection['value'],
            ]);
        }
    }

    /**
     * The PostGIS geometry projection (ADR-0006 D1) — the object-valued sibling of {@see projectIndex},
     * acting on exactly the top-level geo answers that projectIndex skips (arrays/objects are never
     * scalar-indexed). Written inside the caller's persist transaction, so the geometry can never drift from
     * the source-of-truth envelope (Risk R1). Uses a bound raw insert because the geometry is built by
     * `ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)` (Blueprint/Eloquent cannot express a PostGIS function call);
     * `tenant_id` comes from the request GUC the table's FORCE-RLS WITH CHECK matches. Geo inside a
     * repeatable section is banned at publish, so top-level iteration suffices.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $effectiveAnswers
     */
    private function projectGeo(Submission $submission, FormVersion $version, Collection $fields, array $effectiveAnswers): void
    {
        /** @var array<string, FormField> $byKey */
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        $tenantId = TenantContext::currentTenantId();

        foreach ($effectiveAnswers as $key => $value) {
            $field = $byKey[$key] ?? null;
            if ($field === null || ! $field->field_type->isGeo()) {
                continue;
            }

            $projection = $this->geoProjector->project($field, $value);
            if ($projection === null) {
                continue;
            }

            DB::insert(
                'INSERT INTO submission_geo_index '
                .'(tenant_id, submission_id, form_version_id, form_field_id, field_key, '
                .'geometry_type, captured_accuracy, geom, created_at, updated_at) '
                .'VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), now(), now())',
                [
                    $tenantId,
                    $submission->id,
                    $version->id,
                    $field->id,
                    $field->key,
                    $projection['geometry_type'],
                    $projection['captured_accuracy'],
                    $projection['geojson'],
                ],
            );
        }
    }
}
