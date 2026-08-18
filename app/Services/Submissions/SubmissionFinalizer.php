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
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;

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
 *
 * The two projections moved out to {@see SubmissionProjectionWriter} in I9c, unchanged, so the answer-edit
 * path can REPLACE a submission's projections where this one only ever creates them. Nothing about this
 * class's behaviour changed with that extraction.
 *
 * ⚠️ NOT A REUSABLE "SAVE THE ANSWERS" SEAM, despite the name reading like one. It runs
 * {@see FormAcceptanceGuard::assertCapacity()} and writes an `AuditEvent::Created` row, both correct for a
 * submission becoming a submission and both wrong for any later mutation of one: an edit routed through here
 * would 403 on a form that has since hit its `max_responses` cap, and would deposit a "created" ledger entry
 * for a row that may be months old. I9c's {@see SubmissionAnswerEditService} therefore composes the pieces it
 * needs rather than calling this.
 */
final class SubmissionFinalizer
{
    public function __construct(
        private readonly SubmissionProjectionWriter $projections,
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
        $attachmentIds = self::collectAttachmentIds($fields, $answers);
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

        // The scalar + geo projections. INSERT-only (`write()`, not `rewrite()`) because this row has none
        // yet — a fresh submit created it moments ago and a promote's draft never projected. I9c extracted
        // both into {@see SubmissionProjectionWriter} so the answer-edit path can REPLACE them; picking the
        // wrong method here would re-delete and re-insert on every finalize, which is harmless but wasteful
        // and would hide a genuine double-projection bug behind an idempotent purge.
        $this->projections->write($submission, $version, $fields, $answers);

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
     * PUBLIC STATIC since I9c, which needs the identical derivation: an answer edit can PRUNE a media answer
     * (relevance), so it has to rewrite `attachment_refs` from the document it is about to store, or the
     * denormalised list contradicts the JSONB. Sharing this method is what keeps the two write paths from
     * each having their own idea of what a media reference is.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function collectAttachmentIds(Collection $fields, array $answers): array
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
}
