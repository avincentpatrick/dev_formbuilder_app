<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FormVersionStatus;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Services\Attachments\AttachmentReferenceValidator;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticResult;
use App\Services\Validation\SemanticValidator;
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
 *                    projection (ADR-0006) + the `created` audit row (H4); `SubmissionCreated` post-commit.
 *
 * Registered as a singleton so it shares the memoised singleton expression parser/evaluator (via the
 * validator). Media attachments (Increment G6) are linked here: a Stage-3.5 DB check
 * ({@see AttachmentReferenceValidator}) validates each referenced file exists/is owned/is not infected,
 * then persist re-points each staged attachment to the submission and records the id list on the answer
 * document. The shared Stage-4 tail (answer document + typed/geo index + attachment re-point + the `created`
 * audit row) is written by {@see SubmissionFinalizer}, so {@see SubmissionDraftService::promote()} reuses the
 * identical persistence body when it finalizes a draft (Increment H9a).
 */
final class SubmissionPipeline
{
    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly AttachmentReferenceValidator $attachmentRefs,
        private readonly SubmissionFinalizer $finalizer,
        private readonly FormAcceptanceGuard $acceptance,
    ) {}

    public function submit(SubmissionPayload $payload): SubmissionResult
    {
        $version = $payload->version;

        // Stage 2a — a submission may only be created against the published version.
        if ($version->status !== FormVersionStatus::Published) {
            throw SubmissionException::versionNotPublished();
        }

        // Stage 2c (Increment H12a) — scheduled-form acceptance. A FRESH submission may start only inside the
        // open window (opens_at/closes_at, live vs now()); the response cap is enforced transactionally at
        // finalize. Runs early so a refusal is cheap and rolls back nothing. The form is loaded RLS-scoped from
        // the version and threaded into persist() so the finalizer's cap COUNT can lock/read it.
        $form = Form::query()->findOrFail($version->form_id);
        $this->acceptance->assertCanStart($form);

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
            $submission = DB::transaction(fn (): Submission => $this->persist($payload, $form, $fields, $result, $contentChecksum));
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

        event(SubmissionCreated::for($submission)); // post-commit only (scalar-payload domain event)

        return new SubmissionResult($submission, created: true, semantic: $result);
    }

    /**
     * The Stage-4 transaction body: create the head submission row, then delegate the shared tail (the 1:1
     * JSONB answer document + the typed/geo index projections + the attachment re-point + the `created` audit
     * row) to {@see SubmissionFinalizer} — the identical body {@see SubmissionDraftService::promote()} reuses.
     *
     * @param  Collection<int, FormField>  $fields
     */
    private function persist(SubmissionPayload $payload, Form $form, Collection $fields, SemanticResult $result, string $contentChecksum): Submission
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

        $this->finalizer->finalize($submission, $form, $version, $fields, $answers, $contentChecksum, $payload->respondentUserId);

        return $submission;
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
