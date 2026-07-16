<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FormVersionStatus;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Services\Attachments\AttachmentReferenceValidator;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticResult;
use App\Services\Validation\SemanticValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The unified Submission Pipeline (technical-architecture.md §4.1) — the single write path every ingest
 * channel funnels into. `submit()` runs the four stages in order and is the ONLY way a submission is
 * created:
 *
 *   1. Structural  — {@see StructuralAnswerNormalizer}: per-field type coercion, unknown-key + type
 *                    mismatch rejection.
 *   2. Integrity   — the version is published; a replayed `client_submission_uuid` is an idempotent no-op.
 *   3. Semantic    — {@see SemanticValidator}: relevance settle + required + constraints (F3).
 *   4. Persist     — one transaction: `submissions` + `submission_answers` (relevance-pruned JSONB) + the
 *                    typed `submission_answer_index` projection + the `submission_geo_index` PostGIS
 *                    projection (ADR-0006); `SubmissionCreated` dispatched post-commit.
 *
 * Registered as a singleton so it shares the memoised singleton expression parser/evaluator (via the
 * validator). Media attachments (Increment G6) are linked here: a Stage-3.5 DB check
 * ({@see AttachmentReferenceValidator}) validates each referenced file exists/is owned/is not infected,
 * then persist re-points each staged attachment to the submission and records the id list on the answer
 * document. Audit records — the architecture's other Stage-4 insert — are still deferred (no audits table).
 */
final class SubmissionPipeline
{
    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly AnswerIndexProjector $projector,
        private readonly GeoIndexProjector $geoProjector,
        private readonly AttachmentReferenceValidator $attachmentRefs,
    ) {}

    public function submit(SubmissionPayload $payload): SubmissionResult
    {
        $version = $payload->version;

        // Stage 2a — a submission may only be created against the published version.
        if ($version->status !== FormVersionStatus::Published) {
            throw SubmissionException::versionNotPublished();
        }

        $fields = $version->fields()->get();
        $sections = $version->sections()->get();

        // Stage 1 — structural normalisation (throws on unknown key / type mismatch; nests repeat groups).
        $normalized = $this->normalizer->normalize($fields, $sections, $payload->answers);

        // The content checksum (Increment G8c) is taken over the normalized answers so the stored value and
        // any later replay hash the same "what the client submitted" representation, independent of key order.
        $contentChecksum = AnswersContentChecksum::of($normalized);

        // Stage 2b — idempotency: a replayed client_submission_uuid resolves to the existing row. A
        // byte-identical replay (or a legacy row with no stored checksum) is a 200 no-op; the same uuid
        // carrying different content is a genuine concurrent-edit conflict → 409 (Increment G8c, §5).
        if ($payload->clientSubmissionUuid !== null) {
            $existing = $this->findByClientUuid($payload->clientSubmissionUuid);
            if ($existing !== null) {
                if ($this->contentConflicts($existing, $contentChecksum)) {
                    throw SubmissionConflictException::contentConflict();
                }

                return new SubmissionResult($existing, created: false);
            }
        }

        // Stage 3 — semantic validation. A false constraint is a result, not an exception; !passed() → 422.
        $result = $this->semantic->validate($version, $normalized, $payload->locale);
        if (! $result->passed()) {
            throw SubmissionValidationException::semantic($this->mapErrors($result->errors));
        }

        // Stage 3.5 (Increment G6) — the DB-backed half of media validation the shared engine can't do:
        // each referenced attachment must exist, be tenant-owned, be staged for its field, and not be
        // infected. Runs after semantics (so a hidden/pruned media field is already gone) and before persist.
        $this->attachmentRefs->validate($version, $fields, $result->effectiveAnswers);

        // Stage 4 — transactional persist.
        try {
            $submission = DB::transaction(fn (): Submission => $this->persist($payload, $fields, $result, $contentChecksum));
        } catch (QueryException $e) {
            // Race on the (tenant_id, client_submission_uuid) partial-unique index — a concurrent replay
            // won the insert. Resolve to that row: an identical duplicate is a success, not an error (§4.1);
            // but a same-uuid different-content winner is the same conflict Stage 2b guards (Increment G8c).
            if ($payload->clientSubmissionUuid !== null && (string) $e->getCode() === '23505') {
                $existing = $this->findByClientUuid($payload->clientSubmissionUuid);
                if ($existing !== null) {
                    if ($this->contentConflicts($existing, $contentChecksum)) {
                        throw SubmissionConflictException::contentConflict();
                    }

                    return new SubmissionResult($existing, created: false);
                }
            }

            throw $e;
        }

        event(new SubmissionCreated($submission)); // post-commit only

        return new SubmissionResult($submission, created: true, semantic: $result);
    }

    /**
     * The Stage-4 transaction body: the head submission, its 1:1 JSONB answer document, and one typed index
     * row per queryable scalar answer.
     *
     * @param  Collection<int, FormField>  $fields
     */
    private function persist(SubmissionPayload $payload, Collection $fields, SemanticResult $result, string $contentChecksum): Submission
    {
        $version = $payload->version;

        // Write-back (Increment G3): a calculated field's computed value is merged into the persisted answer
        // document alongside the respondent's answers (calc fields never collide — they are dropped in
        // Stage 1, so they are never in effectiveAnswers), then indexed like any other scalar answer.
        $answers = array_merge($result->effectiveAnswers, $result->computed);

        $submission = Submission::create([
            'form_id' => $version->form_id,
            'form_version_id' => $version->id,
            'respondent_user_id' => $payload->respondentUserId,
            'status' => SubmissionStatus::Submitted,
            'source' => $payload->source,
            'client_submission_uuid' => $payload->clientSubmissionUuid,
            'guest_token' => $payload->guestToken,
            'guest_ip' => $payload->guestIp,
            'guest_user_agent' => $payload->guestUserAgent,
            'guest_contact_email' => $payload->guestContactEmail,
            'device_id' => $payload->deviceId,
            'app_version' => $payload->appVersion,
            'locale' => $payload->locale,
            'submitted_at' => now(),
        ]);

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

        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'form_version_id' => $version->id,
            'answers' => $answers,
            'answers_schema_checksum' => $version->checksum,
            'answers_content_checksum' => $contentChecksum,
            'attachment_refs' => $attachmentIds,
            'last_saved_at' => now(),
        ]);

        $this->projectIndex($submission, $version, $fields, $answers);
        $this->projectGeo($submission, $version, $fields, $answers);

        return $submission;
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

    private function findByClientUuid(string $uuid): ?Submission
    {
        return Submission::query()->where('client_submission_uuid', $uuid)->first();
    }

    /**
     * Increment G8c — does the already-persisted submission carry different answer content than this replay?
     * A null stored checksum (a row created before the G8c migration) is treated as "cannot compare" → not a
     * conflict, preserving the pre-G8c idempotent-no-op behaviour so legacy replays never false-conflict.
     */
    private function contentConflicts(Submission $existing, string $incomingChecksum): bool
    {
        $stored = SubmissionAnswer::query()->where('submission_id', $existing->id)->value('answers_content_checksum');

        return is_string($stored) && $stored !== $incomingChecksum;
    }

    /**
     * @param  list<SemanticError>  $errors
     * @return list<array{field: string, rule: string, message: string}>
     */
    private function mapErrors(array $errors): array
    {
        return array_map(static fn (SemanticError $error): array => [
            'field' => $error->path(),
            'rule' => $error->rule,
            'message' => $error->message,
        ], $errors);
    }
}
