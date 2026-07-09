<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FormVersionStatus;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
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
 *                    typed `submission_answer_index` projection; `SubmissionCreated` dispatched post-commit.
 *
 * Registered as a singleton so it shares the memoised singleton expression parser/evaluator (via the
 * validator). Attachments and audit records — the architecture's other Stage-4 inserts — are deferred:
 * their tables do not exist in Phase 1 (same TODO posture as PublishService's audit/webhook side-effects).
 */
final class SubmissionPipeline
{
    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly AnswerIndexProjector $projector,
    ) {}

    public function submit(SubmissionPayload $payload): SubmissionResult
    {
        $version = $payload->version;

        // Stage 2a — a submission may only be created against the published version.
        if ($version->status !== FormVersionStatus::Published) {
            throw SubmissionException::versionNotPublished();
        }

        $fields = $version->fields()->get();

        // Stage 1 — structural normalisation (throws on unknown key / type mismatch).
        $normalized = $this->normalizer->normalize($fields, $payload->answers);

        // Stage 2b — idempotency: a replayed client_submission_uuid resolves to the existing row (no-op).
        if ($payload->clientSubmissionUuid !== null) {
            $existing = $this->findByClientUuid($payload->clientSubmissionUuid);
            if ($existing !== null) {
                return new SubmissionResult($existing, created: false);
            }
        }

        // Stage 3 — semantic validation. A false constraint is a result, not an exception; !passed() → 422.
        $result = $this->semantic->validate($version, $normalized, $payload->locale);
        if (! $result->passed()) {
            throw SubmissionValidationException::semantic($this->mapErrors($result->errors));
        }

        // Stage 4 — transactional persist.
        try {
            $submission = DB::transaction(fn (): Submission => $this->persist($payload, $fields, $result));
        } catch (QueryException $e) {
            // Race on the (tenant_id, client_submission_uuid) partial-unique index — a concurrent replay
            // won the insert. Resolve to that row: the duplicate is a success, not an error (§4.1).
            if ($payload->clientSubmissionUuid !== null && (string) $e->getCode() === '23505') {
                $existing = $this->findByClientUuid($payload->clientSubmissionUuid);
                if ($existing !== null) {
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
    private function persist(SubmissionPayload $payload, Collection $fields, SemanticResult $result): Submission
    {
        $version = $payload->version;

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
            'locale' => $payload->locale,
            'submitted_at' => now(),
        ]);

        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'form_version_id' => $version->id,
            'answers' => $result->effectiveAnswers,
            'answers_schema_checksum' => $version->checksum,
            'attachment_refs' => [],
            'last_saved_at' => now(),
        ]);

        $this->projectIndex($submission, $version, $fields, $result->effectiveAnswers);

        return $submission;
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

    private function findByClientUuid(string $uuid): ?Submission
    {
        return Submission::query()->where('client_submission_uuid', $uuid)->first();
    }

    /**
     * @param  list<SemanticError>  $errors
     * @return list<array{field: string, rule: string, message: string}>
     */
    private function mapErrors(array $errors): array
    {
        return array_map(static fn (SemanticError $error): array => [
            'field' => $error->fieldKey,
            'rule' => $error->rule,
            'message' => $error->message,
        ], $errors);
    }
}
