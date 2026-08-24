<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FormVersionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Services\Attachments\AttachmentReferenceValidator;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticResult;
use App\Services\Validation\SemanticValidator;
use App\Support\Submissions\FinalizedStatus;
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
    /**
     * How many times Stage 4 is re-run after a `submissions.reference` collision (J2e).
     *
     * Two is arguably enough — the per-insert odds are ~9e-7 even in a million-row tenant — but the retry is
     * cheap and only ever runs after a real 23505, so the budget is set where a second failure stops being
     * chance and starts being a symptom.
     */
    private const int MAX_REFERENCE_ATTEMPTS = 3;

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
        //
        // ⚠️ THE RESOLVE IS SCOPED TO THIS FORM AND THIS AUTHOR SINCE M11, AND THAT IS AUTHORIZATION RATHER
        // THAN TIDINESS. It used to filter on the uuid alone while THREE channels fed it — the guest submit
        // (share token fixes the form, uuid comes from the body), the authenticated encode page and
        // /api/v1/sync (both take the version from one input and the uuid from another). So a caller
        // entitled to form A could name form B's uuid and be handed B's id, reference and status with their
        // own answers discarded as an idempotent 200 — and on the encode channel the promote backstop below
        // would FINALIZE B's draft. See {@see ClientUuidResolver} for the full argument.
        if ($payload->clientSubmissionUuid !== null) {
            $existing = ClientUuidResolver::resolve($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
            if ($existing !== null) {
                if ($this->contentConflicts($existing, $contentChecksum)) {
                    throw SubmissionConflictException::contentConflict();
                }

                return new SubmissionResult($existing, created: false);
            }

            // Free within our scope is NOT free in the tenant: the partial unique index is
            // `(tenant_id, client_submission_uuid)`. Refuse here — one indexed EXISTS — rather than let
            // Stage 4 insert into a 23505 whose recovery arm cannot classify it, which is a 500 on a route
            // anyone on the internet can POST to. It also refuses BEFORE Stages 3/3.5, so the cheapest
            // outcome costs the least work.
            ClientUuidResolver::assertUnclaimed($payload->clientSubmissionUuid);
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
        //
        // ⚠️ THE LOOP IS THE REFERENCE-COLLISION RECOVERY (J2e), AND IT HAS TO LIVE OUT HERE. `submissions`
        // carries TWO unique constraints now, and a 23505 from either arrives the same way. A "mint, SELECT,
        // re-mint if taken" probe inside the model hook cannot fix the second one: the insert happens INSIDE
        // `DB::transaction`, so by the time the index objects the PostgreSQL transaction is in ERROR state and
        // every subsequent statement fails 25P02 — re-minting in place is impossible. Retrying the whole
        // closure is: `persist()` builds a fresh `Submission::create()`, whose `creating` hook mints again.
        //
        // The arms are ORDERED rather than told apart by the constraint name, which keeps
        // `SavedReportViewService`'s rule intact (catch the CODE, never match the driver's message text):
        // resolve the client-uuid race FIRST — unchanged behaviour — and only a 23505 that arm cannot explain
        // is treated as a reference collision.
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $submission = DB::transaction(fn (): Submission => $this->persist($payload, $form, $fields, $sections, $result, $contentChecksum));
                break;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23505') {
                    throw $e;
                }

                // Race on the (tenant_id, client_submission_uuid) partial-unique index — a concurrent replay
                // won the insert. Resolve to that row: an identical duplicate is a success, not an error (§4.1);
                // but a same-uuid different-content winner is the same conflict Stage 2b guards (Increment G8c).
                //
                // The THIRD arm (M11) is a caller that raced Stage 2b's entitlement check: the uuid was free
                // in the tenant when we looked and is spent now, by a row outside our scope. That is the same
                // refusal Stage 2b makes, not a reference collision — and telling them apart here is what
                // keeps the retry budget below for the case it was written for.
                if ($payload->clientSubmissionUuid !== null) {
                    $existing = ClientUuidResolver::resolve($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
                    if ($existing !== null) {
                        if ($this->contentConflicts($existing, $contentChecksum)) {
                            throw SubmissionConflictException::contentConflict();
                        }

                        return new SubmissionResult($existing, created: false);
                    }

                    ClientUuidResolver::assertUnclaimed($payload->clientSubmissionUuid);
                }

                // Nothing to resolve to, so this was the reference index. Retry with a fresh code; give up
                // loudly rather than silently once the budget is spent, because at 32^8 codes a second
                // collision on the same insert means something other than chance is wrong.
                if ($attempt >= self::MAX_REFERENCE_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        event(SubmissionCreated::for($submission)); // post-commit only (scalar-payload domain event)

        return new SubmissionResult($submission, created: true, semantic: $result);
    }

    /**
     * The Stage-4 transaction body: create the head submission row, then delegate the shared tail (the 1:1
     * JSONB answer document + the typed/geo index projections + the attachment re-point + the `created` audit
     * row) to {@see SubmissionFinalizer} — the identical body {@see SubmissionDraftService::promote()} reuses.
     *
     * `$sections` is threaded in for {@see FinalizedStatus} alone (I9a). It is NOT re-queried here: `submit()`
     * already loaded it for Stage 1, and the status must be decided from THIS finalize's own masks.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     */
    private function persist(SubmissionPayload $payload, Form $form, Collection $fields, Collection $sections, SemanticResult $result, string $contentChecksum): Submission
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
            // Computed HERE, at the create, rather than into a local above — the head row's status is what
            // `assertCapacity()` counts a few lines later inside `finalize()`, so a value computed early and
            // then not used is the one mistake this line must make impossible. See {@see FinalizedStatus}.
            'status' => FinalizedStatus::for($sections, $fields, $result),
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
