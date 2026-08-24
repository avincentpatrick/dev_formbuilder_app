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

    /**
     * How many times {@see createDraft()} is re-run after a `submissions.reference` collision (J2e). The twin
     * of {@see SubmissionPipeline::MAX_REFERENCE_ATTEMPTS}, kept separate because the two services share no
     * base class and a shared constant would be the only thing coupling them.
     */
    private const int MAX_REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly AttachmentReferenceValidator $attachmentRefs,
        private readonly SubmissionFinalizer $finalizer,
        private readonly FormAcceptanceGuard $acceptance,
    ) {}

    /**
     * Create or update a draft submission from a partial answer set. Runs Stage-1 + integrity (Stage-2a
     * version guard); skips Stage-3 and the 409 CONTENT-conflict rule. Idempotent under one
     * `client_submission_uuid`: the first save creates a `status=draft` row, every later save overwrites it.
     *
     * ⚠️ "SKIPS THE 409" IS TRUE OF THE *CONTENT* RULE ONLY, SINCE INCREMENT P3a. A save that carries a
     * baseline (`$payload->checkBaseline`) is additionally checked for a LOST UPDATE — the stored answers
     * having moved since the saving device read them, which since H9b/H10 means a second device wrote to the
     * same draft. That is a different comparison (base-vs-stored, not incoming-vs-stored) and so does not
     * reinstate what this line says is suspended: a same-device autosave loop always matches its own base and
     * never sees it. A save with no baseline behaves exactly as it did before P3a.
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

        // The content checksum is recomputed + stored on every save, but the 409 CONTENT rule is SUSPENDED
        // for a draft (it re-arms automatically on promotion — see promote()). The same value doubles as
        // P3a's lost-update token on the way back out, which is why nothing here needed a new column.
        $checksum = AnswersContentChecksum::of($normalized);
        $completeness = DraftCompleteness::of($fields, $sections, $normalized);

        if ($payload->clientSubmissionUuid !== null) {
            $existing = ClientUuidResolver::resolve($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
            if ($existing !== null) {
                // Grace window (Increment H12a): an EXISTING draft keeps autosaving even after the form closes,
                // so a respondent mid-fill is never stranded — no start guard on this branch.
                //
                // Increment P3a — the ONE site that carries the lost-update baseline, because it is the only
                // one where the caller genuinely read this draft before writing to it.
                return $this->updateDraft(
                    $existing, $version, $normalized, $checksum, $completeness, $payload->draftCurrentStep,
                    checkBaseline: $payload->checkBaseline,
                    baseline: $payload->baseContentChecksum,
                );
            }
        }

        // ⚠️ AND THE TENANT IS A WIDER DOMAIN THAN THE SCOPE WE JUST SEARCHED (M11). The partial unique index
        // is `(tenant_id, client_submission_uuid)`, so a uuid that is free for THIS form and THIS author can
        // still be spent by another form's row — including a soft-deleted tombstone the resolve cannot see.
        // Left to createDraft() that is a 23505 nothing can classify, three wasted transactions and a 500 on
        // `api/v1/public/f/{shareToken}/draft` — a route anyone on the internet can POST to. Refuse it here,
        // with the same 409 the authenticated draft channel has returned for this cause since I9b.
        if ($payload->clientSubmissionUuid !== null) {
            ClientUuidResolver::assertUnclaimed($payload->clientSubmissionUuid);
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
        // The lost-update token (Increment M12), taken off the SAME row object as the answers rather than by
        // a second query. One read gives both from one row version, so the token can never certify a document
        // this method did not actually read — the identical reasoning `GuestDraftResumeController::show()`
        // states for handing a resuming device its baseline. Re-compared under the lock below.
        $readChecksum = $stored->answers_content_checksum;

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

        $result = DB::transaction(function () use ($draft, $form, $version, $fields, $sections, $final, $checksum, $readChecksum, $actorId, $semantic): SubmissionResult {
            $row = Submission::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            // Already finalized (double-promote, or a concurrent promote won under the lock) — idempotent
            // no-op: do not re-project, re-audit, or re-fire the event.
            if ($row->status !== SubmissionStatus::Draft) {
                return new SubmissionResult($row, created: false);
            }

            // ── ⚠️ THE PRE-LOCK LOST UPDATE (Increment M12) — THE SECOND WRITE DOOR P3a DID NOT CLOSE ───
            // Everything this method is about to finalize was computed BEFORE the lock: the answers at the
            // read above, Stage 3 and the media check over them, `$final`, `$checksum`, and the
            // {@see FinalizedStatus} determination below. And the write is a WHOLE-DOCUMENT replace
            // ({@see SubmissionFinalizer::finalize()}), so an autosave committing inside that window — tens of
            // milliseconds of semantic validation plus a DB attachment check — is silently reverted, and the
            // row is `submitted` afterwards, so no later save can restore it. P3a closed the save-vs-save
            // case in {@see updateDraft()}; this is the save-vs-PROMOTE case, on the same document, through
            // the door that was left open.
            //
            // ⚠️ THE DAMAGE IS NOT ONLY A MISSING ANSWER. {@see FinalizedStatus::for()} reads the pre-lock
            // `$semantic`, and its own docblock demands "this finalize's OWN Stage-3 result — never a cached
            // or borrowed one": a racing save that routes the respondent into a section is the difference
            // between `submitted` and `screened_out`, which is the difference between consuming a
            // `max_responses` slot and not ({@see Submission::scopeConsumesCapacity()}) — and it labels the
            // row "was shown no questions" about somebody who answered some. `attachment_refs` and the
            // attachment ownership re-point derive from the pre-lock `$final` too, so a file the other device
            // uploaded stays owned by its form_field and is unreferenced by the finalized submission.
            //
            // ⚠️ ORDER IS LOAD-BEARING — THIS RUNS AFTER THE STATUS RE-ASSERT, NEVER BEFORE IT. A concurrent
            // PROMOTE that won under the lock moved the status AND the checksum (finalize rewrites both), and
            // that case is a documented idempotent no-op rather than a conflict. Compare the checksum first
            // and a double-promote starts returning 409s where the contract promises an unchanged 200.
            //
            // ⚠️ UNCONDITIONAL, WITH NO `$checkBaseline` FLAG, AND THAT IS THE DIFFERENCE FROM updateDraft()
            // RATHER THAN AN INCONSISTENCY WITH IT. That check compares a token the CLIENT supplied, so it
            // must be opted into and must tolerate a legacy null. This one compares two SERVER reads inside
            // one request: there is no contract to opt into, and a legacy draft's null compares equal to
            // itself. It is the sibling's SECOND check ({@see SubmissionAnswerEditService::edit()}), which is
            // ungated there for the same reason. The two services now hold one check each, and each holds the
            // one its own read/lock ordering makes authoritative.
            //
            // An identical-content autosave in the window does NOT refuse: {@see AnswersContentChecksum::of()}
            // is deterministic, so an unchanged document leaves the stored value where it was. Nothing was
            // lost, so nothing is refused.
            $current = SubmissionAnswer::query()->where('submission_id', $row->id)->value('answers_content_checksum');
            if ($current !== $readChecksum) {
                throw SubmissionConflictException::draftConcurrentlyModified();
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
    private function updateDraft(Submission $existing, FormVersion $version, array $normalized, string $checksum, int $completeness, ?string $currentStep = null, bool $checkBaseline = false, ?string $baseline = null): SubmissionResult
    {
        return DB::transaction(function () use ($existing, $version, $normalized, $checksum, $completeness, $currentStep, $checkBaseline, $baseline): SubmissionResult {
            $row = Submission::query()->whereKey($existing->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== SubmissionStatus::Draft) {
                throw SubmissionConflictException::draftAlreadyFinalized();
            }

            // ── ⚠️ THE LOST-UPDATE GUARD (Increment P3a) — WITHOUT IT TWO DEVICES SILENTLY OVERWRITE ────────
            // The write below is a WHOLE-DOCUMENT replace, so a save based on a state another device has since
            // moved past reverts every answer that device saved, with no error to either of them. This was
            // reproduced before it was fixed: seed {age}, device A adds {country}, device B saves from the
            // pre-A state, and `country` is simply gone with `created: false` and no exception.
            //
            // ⚠️ THIS ONLY BECAME REACHABLE WHEN H9b/H10 SHIPPED CROSS-DEVICE RESUME. `saveDraft()`'s written
            // suspension of the content 409 was authored for the same-device draft, where the only writer is
            // the one autosave loop, and is still correct for it — a NO-BASELINE save behaves exactly as
            // before. The resume link (`GuestDraftResumeController`) carries the SAME
            // `client_submission_uuid` to a second device, which is what created a second writer.
            //
            // ⚠️ THE TOKEN COMES FROM THE CLIENT, AND A SERVER-SIDE RE-READ CANNOT SUBSTITUTE — the sibling
            // channel already learned this and wrote it down ({@see SubmissionAnswerEditService::edit()},
            // which notes its first attempt compared two reads inside ONE request and was therefore always
            // equal). The lost update spans two REQUESTS, so only the base the device actually holds can
            // detect it.
            //
            // ⚠️ ONE CHECK, NOT THE SIBLING'S TWO, AND THE DIFFERENCE IS LOAD-BEARING RATHER THAN AN
            // OMISSION. `SubmissionAnswerEditService` reads its `$stored` BEFORE opening the transaction, so
            // it needs a second check under the lock. Here the row lock is already held (line above) and the
            // read below happens after it: under READ COMMITTED a query issued after the lock is granted sees
            // the winner's committed write, so this single check is itself the authoritative one. Adding a
            // second would be dead code, and a redundant partner is exactly what P1e's mutation pass found
            // masking real gaps.
            if ($checkBaseline) {
                $storedChecksum = SubmissionAnswer::query()
                    ->where('submission_id', $row->id)
                    ->value('answers_content_checksum');

                // Strict, though a LOOSE comparison survives the mutation pass (M11) and is provably
                // equivalent over today's domain: the value is a 64-hex string or null, pinned by the
                // requests' `size:64` rule and their accessors mapping '' to null, so no juggling case is
                // reachable. It stays strict because that equivalence is a property of the DOMAIN, not of the
                // comparison — widen the column or relax the rule and loose equality starts admitting pairs
                // this must refuse.
                if ($storedChecksum !== $baseline) {
                    throw SubmissionConflictException::draftConcurrentlyModified();
                }
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

            return new SubmissionResult($row, created: false, contentChecksum: $checksum);
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

        // ⚠️ THE LOOP IS THE REFERENCE-COLLISION RECOVERY (J2e) — the twin of the one in
        // {@see SubmissionPipeline::submit()}, and it exists for the same reason: `submissions` now carries
        // TWO unique constraints, the insert happens INSIDE `DB::transaction`, and a 23505 leaves that
        // transaction in ERROR state, so re-minting in place is impossible (25P02). Re-running the closure
        // builds a fresh `Submission::create()` whose `creating` hook mints a new code.
        //
        // The arms are ORDERED rather than told apart by the constraint name, so this keeps catching the
        // error CODE and never matches the driver's message text: resolve the client-uuid race first —
        // unchanged behaviour — and treat only an unexplained 23505 as a reference collision.
        $attempt = 0;

        while (true) {
            $attempt++;

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

                    return new SubmissionResult($submission, created: true, contentChecksum: $checksum);
                });
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23505') {
                    throw $e;
                }

                // Race on the (tenant_id, client_submission_uuid) partial-unique index — a concurrent first-save
                // won. DB::transaction has already rolled back; fold into the update path (last-writer-wins).
                //
                // ⚠️ P3a DELIBERATELY DOES NOT CARRY THE BASELINE THROUGH THIS FOLD, and the reason is
                // retry idempotency rather than tidiness. A device reaching createDraft() is claiming
                // "nothing exists here", so its baseline is null by construction — and the winner of the race
                // has just written a non-null checksum. Checking would therefore 409 EVERY lost insert race,
                // including the commonest one by far: a plain network retry of a device's own first save,
                // where both requests carry identical content and nothing was lost at all. The documented
                // last-writer-wins recovery is left exactly as it was.
                // ⚠️ `checkBaseline: false` IS PASSED EXPLICITLY RATHER THAN LEFT TO THE DEFAULT, and that is
                // the mutation pass earning its keep: adding the check here survives every test in this
                // repository (M12), because reaching this line needs a genuine insert race — ClientUuidResolver::resolve()
                // must return null and then non-null — which no deterministic test can stage. The omission is
                // therefore enforced by this argument and the comment above it, not by a red test. Say so
                // rather than let the next reader assume a gate exists.
                if ($payload->clientSubmissionUuid !== null) {
                    $existing = ClientUuidResolver::resolve($payload->clientSubmissionUuid, $version->form_id, $payload->respondentUserId);
                    if ($existing !== null) {
                        return $this->updateDraft(
                            $existing, $version, $normalized, $checksum, $completeness, $payload->draftCurrentStep,
                            checkBaseline: false,
                        );
                    }

                    // Raced our own entitlement check (M11): the uuid was free in the tenant when saveDraft()
                    // looked and is spent now, by a row we cannot fold into. That is a refusal, not a
                    // reference collision — without it the retry budget below is spent on an insert that can
                    // only fail again, and the QueryException that escapes is a 500 on an unauthenticated route.
                    ClientUuidResolver::assertUnclaimed($payload->clientSubmissionUuid);
                }

                // Nothing to fold into, so this was the reference index. Retry with a fresh code, then give up
                // loudly — at 32^8 codes a second collision on one insert is a symptom, not chance.
                if ($attempt >= self::MAX_REFERENCE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
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
