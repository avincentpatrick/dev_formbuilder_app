<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AuditEvent;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shared Stage-4 tail of the Submission write path (technical-architecture.md §4.1), extracted (Increment
 * H9a) so the two ways a submission is FINALIZED reuse one identical persistence body:
 *
 *   - {@see SubmissionPipeline::submit()} — the ordinary fresh-submission path (all four stages).
 *   - {@see SubmissionDraftService::promote()} — promoting a `status=draft` row in place (Stage-3 runs once,
 *     then this tail).
 *
 * Given an already-created (submit) or freshly-flipped (promote) submission head row and its FINAL answers
 * (`effectiveAnswers + computed`), it: re-points every referenced media attachment's polymorphic owner to the
 * submission; writes the 1:1 JSONB answer document (`updateOrCreate` so promotion overwrites the draft's own
 * answer row); projects one typed `submission_answer_index` row per queryable scalar answer + the PostGIS
 * `submission_geo_index` geometry rows; and writes the atomic `created` audit row (H4). Runs entirely inside
 * the caller's persist transaction. It does NOT fire `SubmissionCreated` — that stays a post-commit concern of
 * the caller, fired exactly once when the submission truly becomes a submission.
 */
final class SubmissionFinalizer
{
    public function __construct(
        private readonly AnswerIndexProjector $projector,
        private readonly GeoIndexProjector $geoProjector,
        private readonly AuditLogger $audit,
        private readonly FormAcceptanceGuard $acceptance,
    ) {}

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers  the FINAL answers Stage 4 persists (effectiveAnswers + computed)
     */
    public function finalize(
        Submission $submission,
        Form $form,
        FormVersion $version,
        Collection $fields,
        array $answers,
        string $contentChecksum,
        ?string $actorId,
    ): void {
        // Scheduled-form response cap (Increment H12a) — the ONE transactional capacity gate, run here so both
        // finalize paths (submit + promote) share it. The head row already exists and already carries its
        // finalized status (FinalizedStatus::for, I9a — set at create on submit, at the forceFill on promote),
        // so the live COUNT-under-RLS includes it whenever it consumes a slot and correctly excludes it when
        // it is `screened_out`. That ordering is load-bearing: compute the status after this line and a
        // screened-out respondent would still be counted against the cap. A full cap throws and rolls back
        // this whole persist transaction (uncreating the submit / leaving the draft resumable). No-op for an
        // uncapped form. See FormAcceptanceGuard::assertCapacity for the form-row-lock serialization.
        $this->acceptance->assertCapacity($form);

        // Media attachments (Increment G6): collect every referenced attachment id from the media answers,
        // re-point each staged file's polymorphic owner from its form_field to this submission, and record
        // the flat id list on the answer document (attachment_refs). All inside the persist transaction, so
        // the ownership move can never drift from the answer it belongs to. RLS scopes the update to the tenant.
        $attachmentIds = $this->collectAttachmentIds($fields, $answers);
        if ($attachmentIds !== []) {
            Attachment::query()->whereIn('id', $attachmentIds)->update([
                'attachable_type' => 'submission',
                'attachable_id' => $submission->id,
            ]);
        }

        // updateOrCreate keyed on the PK: an identity insert for a fresh submit (no prior answer row), an
        // overwrite of the draft's own answer document on promotion (H9a). The column set is exactly the
        // submit set, so submit()'s observable behaviour is unchanged.
        SubmissionAnswer::updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'form_version_id' => $version->id,
                'answers' => $answers,
                'answers_schema_checksum' => $version->checksum,
                'answers_content_checksum' => $contentChecksum,
                'attachment_refs' => $attachmentIds,
                'last_saved_at' => now(),
            ],
        );

        $this->projectIndex($submission, $version, $fields, $answers);
        $this->projectGeo($submission, $version, $fields, $answers);

        // Stage 4's other insert (technical-architecture §4.1): the `created` audit row, IN this transaction
        // so it is atomic with the submission. Head-row attributes only — deliberately NOT the guest PII
        // (guest_ip / guest_user_agent / guest_contact_email), which the ledger must never carry (spec §2).
        $this->audit->record(
            AuditEvent::Created,
            'submission',
            (string) $submission->id,
            old: null,
            new: [
                'form_id' => $submission->form_id,
                'form_version_id' => $submission->form_version_id,
                'status' => $submission->status->value,
                'source' => $submission->source->value,
            ],
            actorId: $actorId,
        );
    }

    /**
     * The unique attachment ids referenced by every media answer (Increment G6). Reads the `id` of each
     * {@see StructuralAnswerNormalizer} canonical AttachmentRef under a media
     * field key. Feeds both the ownership re-point and the denormalised `attachment_refs` list.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    private function collectAttachmentIds(Collection $fields, array $answers): array
    {
        /** @var array<string, true> $mediaKeys */
        $mediaKeys = [];
        foreach ($fields as $field) {
            if ($field->field_type->isMedia()) {
                $mediaKeys[$field->key] = true;
            }
        }

        /** @var array<string, true> $ids */
        $ids = [];
        foreach ($answers as $key => $value) {
            if (! isset($mediaKeys[$key]) || ! is_array($value)) {
                continue;
            }
            foreach ($value as $ref) {
                if (is_array($ref) && isset($ref['id']) && is_string($ref['id'])) {
                    $ids[$ref['id']] = true;
                }
            }
        }

        return array_keys($ids);
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
     * scalar-indexed). Written inside the same persist transaction as the JSONB, so the geometry can
     * never drift from the source-of-truth envelope (Risk R1). Uses a bound raw insert because the
     * geometry is built by `ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)` (Blueprint/Eloquent cannot express
     * a PostGIS function call); `tenant_id` comes from the request GUC the table's FORCE-RLS WITH CHECK
     * matches. Geo inside a repeatable section is banned at publish, so top-level iteration suffices.
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
