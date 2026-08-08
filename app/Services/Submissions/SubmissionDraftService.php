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
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Services\Attachments\AttachmentReferenceValidator;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticValidator;
use App\Support\Submissions\FinalizedStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The server-side DRAFT substrate (Increment H9a) — the write path a partial submission takes before it is
 * finalized, shared by save-and-resume (H10) and OCR staging (H18/H19). Exposed as a SERVICE seam (not only an
 * HTTP route), so a server-side caller — an OCR job — can stage a draft without a guest request or a
 * `client_submission_uuid`.
 *
 * A draft differs from an ordinary submission in exactly two pipeline-shaped ways (technical-architecture.md
 * §4.1):
 *   - {@see saveDraft()} runs Stage-1 (normalize) — a draft may be INCOMPLETE but never MALFORMED — and skips
 *     Stage-3 (semantic). Crucially the same-uuid-different-content 409 rule is SUSPENDED: a draft legitimately
 *     changes under one `client_submission_uuid` on every autosave, so each save OVERWRITES the draft's answer
 *     document in place (find-or-create by uuid) rather than conflicting.
 *   - {@see promote()} runs the full Stage-3 EXACTLY ONCE and, on pass, flips the SAME row `draft → submitted`
 *     in place (a stable `submissions.id` a resume token can bind to), delegating the shared Stage-4 tail to
 *     {@see SubmissionFinalizer}. This RE-ARMS idempotency: the finalized row now carries its content checksum,
 *     so a later {@see SubmissionPipeline::submit()} replay under the same uuid is an ordinary 200 no-op /
 *     409 as appropriate.
 *
 * A never-metered draft becomes a metered submission only here: {@see SubmissionCreated} fires once, on
 * promotion, exactly as it does for a fresh {@see SubmissionPipeline::submit()}.
 */
final class SubmissionDraftService
{
    /** The draft-expiry default in days (docs/ux/form-filling-ux-flow.md §5.2, pricing-matrix). Stamped once at
     * draft creation. H10 lets a tenant override the window: the caller resolves the tenant's `draft_ttl_days`
     * and passes it as {@see SubmissionPayload::$ttlDays}, and this constant is the fallback when none is set. */
    public const DRAFT_TTL_DAYS = 30;

    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly AttachmentReferenceValidator $attachmentRefs,
        private readonly SubmissionFinalizer $finalizer,
        private readonly FormAcceptanceGuard $acceptance,
    ) {}

    /**
     * Create or update a draft submission from a partial answer set. Runs Stage-1 + integrity (Stage-2a
     * version guard); skips Stage-3 and the 409 content-conflict rule. Idempotent under one
     * `client_submission_uuid`: the first save creates a `status=draft` row, every later save overwrites it.
     */
    public function saveDraft(SubmissionPayload $payload): SubmissionResult
    {
        $version = $payload->version;

        // Stage 2a — a draft may only be saved against the published version (integrity runs for drafts).
        if ($version->status !== FormVersionStatus::Published) {
            throw SubmissionException::versionNotPublished();
        }

        $fields = $version->fields()->get();
        $sections = $version->sections()->get();

        // Stage 1 — structural normalisation (throws SubmissionValidationException::structural on an unknown
        // key / type mismatch; a draft rejects MALFORMED input, not INCOMPLETE input). Stage 3 is NOT run.
        $normalized = $this->normalizer->normalize($fields, $sections, $payload->answers);

        // The content checksum is recomputed + stored on every save, but the 409 conflict rule is SUSPENDED
        // for a draft (it re-arms automatically on promotion — see promote()).
        $checksum = AnswersContentChecksum::of($normalized);
        $completeness = DraftCompleteness::of($fields, $sections, $normalized);

        if ($payload->clientSubmissionUuid !== null) {
            $existing = $this->findByClientUuid($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
            if ($existing !== null) {
                // Grace window (Increment H12a): an EXISTING draft keeps autosaving even after the form closes,
                // so a respondent mid-fill is never stranded — no start guard on this branch.
                return $this->updateDraft($existing, $version, $normalized, $checksum, $completeness, $payload->draftCurrentStep);
            }
        }

        // A NEW draft may only start inside the open window (H12a). Capacity is NOT gated at draft-create (a
        // draft isn't a finalized row) — the authoritative cap runs at promote's finalize.
        $form = Form::query()->findOrFail($version->form_id);
        $this->acceptance->assertCanStart($form);

        return $this->createDraft($payload, $normalized, $checksum, $completeness);
    }

    /**
     * Finalize a draft in place: run the full Stage-3 exactly once and, on pass, flip the SAME row
     * `draft → submitted`, delegating the shared Stage-4 tail to {@see SubmissionFinalizer} and firing
     * {@see SubmissionCreated} once. A Stage-3 failure leaves the draft untouched and resumable. A row already
     * finalized (double-promote / a concurrent promote) is an idempotent no-op that fires nothing.
     *
     * `$actorId` is the acting user (an encoder confirming an OCR draft); it falls back to the draft's own
     * respondent for the `created` audit row.
     */
    public function promote(Submission $draft, ?string $actorId = null): SubmissionResult
    {
        // Cheap pre-check: an already-finalized row is an idempotent no-op — skip re-validation entirely. The
        // in-transaction re-check under a row lock (below) is the authoritative guard against a true concurrent
        // promote; this only spares the obvious sequential double-promote from re-running Stage 3.
        if ($draft->status !== SubmissionStatus::Draft) {
            return new SubmissionResult($draft, created: false);
        }

        $version = FormVersion::findOrFail($draft->form_version_id);

        // Stage 2a — promotion re-asserts the version is still published (the same backstop submit() applies),
        // so a draft against a superseded version fails loudly rather than finalizing silently.
        if ($version->status !== FormVersionStatus::Published) {
            throw SubmissionException::versionNotPublished();
        }

        // Scheduled-form grace window (Increment H12a): a draft STARTED before the form closed may still be
        // promoted after close (the whole point of save-and-resume) — only a draft created at/after the close
        // instant is refused. The response cap is still enforced transactionally in the finalizer below.
        $form = Form::query()->findOrFail($draft->form_id);
        $this->acceptance->assertCanPromote($form, $draft);

        $fields = $version->fields()->get();
        // Loaded for {@see FinalizedStatus} (I9a) — and it is load-bearing rather than tidy. `StepProjection`
        // walks SECTIONS; hand it an empty collection and every projection is empty, so every promoted draft
        // would be classified `screened_out` and no promote would ever consume a capacity slot again.
        $sections = $version->sections()->get();

        $stored = SubmissionAnswer::query()->where('submission_id', $draft->id)->firstOrFail();
        /** @var array<string, mixed> $answers */
        $answers = $stored->answers;

        // Stage 3 (full, exactly once) — runs on promotion only. No transaction is open yet, so a semantic
        // failure leaves the draft row untouched and resumable.
        $semantic = $this->semantic->validate($version, $answers, $draft->locale);
        if (! $semantic->passed()) {
            throw SubmissionValidationException::semantic($this->mapErrors($semantic->errors));
        }

        // Stage 3.5 — the DB-backed media check, against the relevance-pruned effective answers.
        $this->attachmentRefs->validate($version, $fields, $semantic->effectiveAnswers);

        $final = array_merge($semantic->effectiveAnswers, $semantic->computed);
        // Re-arm: the stored content checksum is taken over the NORMALIZED draft answers — identical to what
        // submit() would store for an equivalent raw submission (submit() hashes its Stage-1 output).
        $checksum = AnswersContentChecksum::of($answers);

        $result = DB::transaction(function () use ($draft, $form, $version, $fields, $sections, $final, $checksum, $actorId, $semantic): SubmissionResult {
            $row = Submission::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            // Already finalized (double-promote, or a concurrent promote won under the lock) — idempotent
            // no-op: do not re-project, re-audit, or re-fire the event.
            if ($row->status !== SubmissionStatus::Draft) {
                return new SubmissionResult($row, created: false);
            }

            $row->forceFill([
                // The same determination `SubmissionPipeline::persist()` makes, from the same object, so the
                // two finalize doors cannot disagree about whether a response consumed a paid slot. It is
                // written BEFORE `finalize()` runs `assertCapacity()`, which counts this very row.
                'status' => FinalizedStatus::for($sections, $fields, $semantic),
                'submitted_at' => now(),
                'draft_expires_at' => null,
            ])->save();

            $this->finalizer->finalize($row, $form, $version, $fields, $final, $checksum, $actorId ?? $row->respondent_user_id);

            return new SubmissionResult($row, created: true, semantic: $semantic);
        });

        // Post-commit only (mirrors submit()), fired exactly once — never on the no-op promote path.
        if ($result->created) {
            event(SubmissionCreated::for($result->submission));
        }

        return $result;
    }

    /**
     * Overwrite an existing draft's answer document in place (the suspension of the 409 rule). Re-asserts the
     * row is still a draft under a row lock, so a save that loses to a concurrent promotion cannot mutate a
     * finalized submission.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function updateDraft(Submission $existing, FormVersion $version, array $normalized, string $checksum, int $completeness, ?string $currentStep = null): SubmissionResult
    {
        return DB::transaction(function () use ($existing, $version, $normalized, $checksum, $completeness, $currentStep): SubmissionResult {
            $row = Submission::query()->whereKey($existing->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== SubmissionStatus::Draft) {
                throw SubmissionConflictException::draftAlreadyFinalized();
            }

            // The draft's answer document holds the NORMALIZED (un-pruned) answers; attachment_refs is left at
            // its default and is computed only at promotion (SubmissionFinalizer), where ownership is re-pointed.
            SubmissionAnswer::updateOrCreate(
                ['submission_id' => $row->id],
                [
                    'form_version_id' => $version->id,
                    'answers' => $normalized,
                    'answers_schema_checksum' => $version->checksum,
                    'answers_content_checksum' => $checksum,
                    'last_saved_at' => now(),
                ],
            );

            // The resume cursor moves with each save; `draft_expires_at` is deliberately NOT restamped (a
            // draft's expiry is fixed at creation — the H9a/H10 stamp-once contract the reaper depends on).
            //
            // ── I9b CONSIDERED RESTAMPING ON EVERY SAVE AND DECIDED AGAINST IT ─────────────────────────────
            // The case for restamping is real: `docs/ux/form-filling-ux-flow.md` describes the window as
            // "inactivity", a keyer or guest actively working on day 29 loses everything on day 30, and
            // restamping cannot immortalise an ABANDONED draft, because this method is only reached when
            // somebody is actively saving. I9b makes it likelier to bite, since staff drafts will be more
            // numerous and longer-lived than guest ones.
            //
            // It was rejected on scope, not on merit. Stamp-once is not an unwritten reading here — it is a
            // deliberate contract with a test named after it ("does not restamp the draft expiry on a later
            // save (stamp-once)", `GuestDraftRuntimeTest`). Flipping it is a behaviour change to a SHIPPED
            // GUEST path, made inside an increment about the encode path, and it would land as an amended
            // assertion in a file I9b otherwise does not touch. Instead I9b makes the deletion VISIBLE rather
            // than silent — the resume banner names the expiry date — which addresses the failure mode that
            // actually harms someone (work vanishing without warning) without redefining the window.
            //
            // Revisit deliberately, with the guest channel in scope, if a real draft is ever reaped from
            // under an active keyer. That is the increment where the assertion should be rewritten.
            $row->forceFill([
                'completeness_percent' => $completeness,
                'last_saved_at' => now(),
                // Preserved when the caller sends none (I9b). The submit path builds its payload without a
                // step, and force-filling null there wiped the resume cursor of any draft whose Submit then
                // failed Stage 3 — so the keyer was bounced back to step 1 of their own half-finished work.
                'draft_current_step' => $currentStep ?? $row->draft_current_step,
            ])->save();

            return new SubmissionResult($row, created: false);
        });
    }

    /**
     * Create a fresh `status=draft` submission + its answer document, stamping the 30-day expiry. Handles the
     * `(tenant_id, client_submission_uuid)` insert race by folding a lost first-save into the update path.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function createDraft(SubmissionPayload $payload, array $normalized, string $checksum, int $completeness): SubmissionResult
    {
        $version = $payload->version;

        try {
            return DB::transaction(function () use ($payload, $version, $normalized, $checksum, $completeness): SubmissionResult {
                $submission = Submission::create([
                    'form_id' => $version->form_id,
                    'form_version_id' => $version->id,
                    'respondent_user_id' => $payload->respondentUserId,
                    'status' => SubmissionStatus::Draft,
                    'source' => $payload->source,
                    'client_submission_uuid' => $payload->clientSubmissionUuid,
                    'guest_token' => $payload->guestToken,
                    'guest_ip' => $payload->guestIp,
                    'guest_user_agent' => $payload->guestUserAgent,
                    'guest_contact_email' => $payload->guestContactEmail,
                    'device_id' => $payload->deviceId,
                    'app_version' => $payload->appVersion,
                    'locale' => $payload->locale,
                    'completeness_percent' => $completeness,
                    'last_saved_at' => now(),
                    // Stamp-once: the tenant-configured TTL (H10) resolved by the caller, falling back to the
                    // 30-day default. A later save never restamps this — see updateDraft().
                    'draft_expires_at' => now()->addDays($payload->ttlDays ?? self::DRAFT_TTL_DAYS),
                    'draft_current_step' => $payload->draftCurrentStep,
                ]);

                SubmissionAnswer::create([
                    'submission_id' => $submission->id,
                    'form_version_id' => $version->id,
                    'answers' => $normalized,
                    'answers_schema_checksum' => $version->checksum,
                    'answers_content_checksum' => $checksum,
                    'last_saved_at' => now(),
                ]);

                return new SubmissionResult($submission, created: true);
            });
        } catch (QueryException $e) {
            // Race on the (tenant_id, client_submission_uuid) partial-unique index — a concurrent first-save
            // won. DB::transaction has already rolled back; fold into the update path (last-writer-wins).
            if ($payload->clientSubmissionUuid !== null && (string) $e->getCode() === '23505') {
                $existing = $this->findByClientUuid($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
                if ($existing !== null) {
                    return $this->updateDraft($existing, $version, $normalized, $checksum, $completeness, $payload->draftCurrentStep);
                }
            }

            throw $e;
        }
    }

    /**
     * Resolve the draft this uuid names, WITHIN one form and one author.
     *
     * ⚠️ THE SCOPING IS AUTHORIZATION, NOT TIDINESS (added in I9b). The lookup used to be
     * `where('client_submission_uuid', $uuid)` alone, which is safe only while every caller's form is pinned
     * by something the caller cannot choose — true of the guest channel, where the share token fixes the form
     * and there is no author. It stopped being true the moment an AUTHENTICATED endpoint took the form from
     * the URL and the uuid from the request body: those are two independent inputs, so a member authorized on
     * form A could send form B's uuid and have the write land on B's draft, on a form they may hold no grant
     * on at all. RLS bounds the blast radius to the tenant and no further.
     *
     * `$respondentUserId` narrows it again on the encode channel, where a draft genuinely belongs to the
     * person keying it. Null is the guest case and matches guest rows (`respondent_user_id IS NULL`) — hence
     * `whereNull`, because `where(col, null)` compiles to `= NULL` and never matches anything.
     */
    private function findByClientUuid(string $uuid, string $formId, ?string $respondentUserId): ?Submission
    {
        return Submission::query()
            ->where('client_submission_uuid', $uuid)
            ->where('form_id', $formId)
            ->when(
                $respondentUserId === null,
                fn ($q) => $q->whereNull('respondent_user_id'),
                fn ($q) => $q->where('respondent_user_id', $respondentUserId),
            )
            ->first();
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
