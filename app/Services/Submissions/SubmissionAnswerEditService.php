<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionUpdated;
use App\Exceptions\Submissions\SubmissionEditException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticValidator;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Submissions\FinalizedStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Post-submission answer editing (Increment I9c, PRD Feature #12) — correcting the answers of an ALREADY
 * FINALIZED submission. The last of I9's three verticals, and the first consumer of `submissions.edit.any` /
 * `submissions.edit.own`, seeded since Phase 0 with no code behind them ({@see SubmissionPolicy::update()}).
 *
 * Built on {@see SubmissionReviewService::apply()}'s template — lock, legality gate, snapshot, mutate, audit
 * with an EXPLICIT actor, announce post-commit by call-site ordering — because the two are the same shape of
 * act and diverging on the mechanics would mean two answers to "how does a guarded submission mutation work".
 *
 * ── WHAT IT IS NOT ──────────────────────────────────────────────────────────────────────────────────────
 * ⚠️ It does NOT reuse {@see SubmissionFinalizer::finalize()}, which reads like the obvious seam and is the
 * wrong one twice over: it runs {@see FormAcceptanceGuard::assertCapacity()}, so correcting a typo would 403
 * once the form reached its `max_responses` cap — the edit consumes no new slot and the row was counted
 * months ago — and it writes an `AuditEvent::Created` row, which would tell the compliance ledger that an
 * old submission was created today. This service composes the pieces it actually needs instead.
 *
 * ⚠️ It does NOT re-derive {@see FinalizedStatus}. An edit never changes whether a
 * response consumed a capacity slot: `screened_out` rows are refused entry (see {@see self::EDITABLE}), and
 * a `submitted` row emptied down to nothing stays `submitted`. Re-deriving would let an edit silently free
 * or consume a paid slot, which is a billing event disguised as a typo fix.
 */
final class SubmissionAnswerEditService
{
    /**
     * The states whose answers may be corrected — deliberately the same four
     * {@see SubmissionReviewService::archive()} accepts, and for overlapping reasons.
     *
     * `draft` is absent because a draft's answers are edited through I9b's resume page, which is the richer
     * surface (autosave, completeness, a resume cursor); the controller redirects there rather than refusing.
     *
     * ⚠️ `screened_out` AND `archived` ARE DELIBERATELY ABSENT, not merely un-added, and
     * {@see SubmissionEditException::illegalState()} spells out each reason at the point of refusal. The
     * screened-out one is the load-bearing half: that state consumes no `max_responses` slot
     * ({@see Submission::scopeConsumesCapacity()}, I9a), so an edit that put real answers on such a row would
     * have to either flip it to `submitted` — consuming a paid slot, possibly past a cap that is already
     * full — or leave answers on a row whose state means "was shown no questions". That is the identical
     * hazard `SubmissionReviewService::archive()`'s `$from` list refuses in writing, and it takes the
     * identical answer: a separate `capacity_consumed_at` column, never a fifth entry here.
     *
     * `SubmissionAnswerEditTest` asserts the accepted set is exactly these four, so a future widening has to
     * argue with a failing assertion rather than slip in.
     *
     * @var list<SubmissionStatus>
     */
    public const EDITABLE = [
        SubmissionStatus::Submitted,
        SubmissionStatus::UnderReview,
        SubmissionStatus::Approved,
        SubmissionStatus::Returned,
    ];

    public function __construct(
        private readonly StructuralAnswerNormalizer $normalizer,
        private readonly SemanticValidator $semantic,
        private readonly SubmissionProjectionWriter $projections,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Correct a finalized submission's answers.
     *
     * `$version` is the SUBMISSION'S OWN pinned version, resolved by the caller. Authorization is the
     * `can:update,submission` route middleware ({@see SubmissionPolicy::update()}); this service assumes it
     * has passed and enforces only what the policy structurally cannot: the legal source states, under a
     * lock, on the row as it actually is now.
     *
     * @param  array<string, mixed>  $answers  the editor's submitted answers (non-media only — see mergeMedia())
     * @param  bool  $checkBaseline  whether `$baseline` is a real claim. False for a server-side caller with
     *                               no page behind it; true for every HTTP edit.
     * @param  ?string  $baseline  the `answers_content_checksum` the editor's PAGE was rendered from — the
     *                             optimistic-concurrency token. Legitimately null for a submission stored
     *                             before the pipeline stamped one, which is why the CHECK is gated on its own
     *                             flag rather than on `$baseline !== null`.
     *
     * @throws SubmissionEditException the row is in a state whose answers may not be corrected
     * @throws SubmissionValidationException Stage 1 (malformed) or Stage 3 (semantic) rejected the edit
     */
    public function edit(Submission $submission, FormVersion $version, array $answers, User $editor, bool $checkBaseline = false, ?string $baseline = null): Submission
    {
        // Cheap pre-check on the passed-in row so an obviously illegal edit fails before Stage 3 runs. The
        // authoritative check is the identical one under the lock below — this one only spares the work.
        $this->assertEditable($submission->status);

        $fields = $version->fields()->get();
        $sections = $version->sections()->get();

        $stored = SubmissionAnswer::query()->where('submission_id', $submission->id)->firstOrFail();
        /** @var array<string, mixed> $storedAnswers */
        $storedAnswers = $stored->answers;
        // ── ⚠️ THE LOST-UPDATE GUARD, AND WITHOUT IT TWO EDITORS SILENTLY OVERWRITE EACH OTHER ────────────
        // The write below is a WHOLE-DOCUMENT replace, so if editor B saves a page rendered before editor A
        // saved theirs, B's document reverts every field A corrected — with no error — and B's audit row is
        // diffed against B's stale snapshot: it records B's one change and says nothing about undoing A's.
        // Replaying the ledger (which `answerDiff()` tells readers to do to reconstruct history) would then
        // produce a document that never existed.
        //
        // ⚠️ THE TOKEN HAS TO COME FROM THE CLIENT, and a server-side-only re-read cannot substitute. The
        // first attempt at this compared a value read at the top of THIS method against one read under the
        // lock — which is always equal, because both reads happen inside one request with nothing
        // interleaved. The lost update spans two REQUESTS, so the only thing that can detect it is the
        // baseline the editor's page was actually rendered from. A test written for the wrong version of
        // this guard is what exposed it.
        //
        // `answers_content_checksum` is the token because it already exists, is written on every finalize
        // and every edit, and changes exactly when the document does. This is the same posture
        // `routes/tenant.php` records for the builder's content edits ("carry an updated_at token → 409 on
        // drift") and the same 409 shape `FormBuilderService` uses.
        if ($checkBaseline && $stored->answers_content_checksum !== $baseline) {
            throw SubmissionEditException::concurrentlyModified();
        }

        $readChecksum = $stored->answers_content_checksum;

        // Stage 1 — structural normalisation, over the merged document (see mergeMedia).
        $merged = $this->mergeMedia($fields, $storedAnswers, $answers);
        $normalized = $this->normalizer->normalize($fields, $sections, $merged);

        // Stage 3 — full semantic validation, exactly as a fresh submit runs it. No transaction is open yet,
        // so a rejection leaves the stored document untouched.
        //
        // ⚠️ THE CLOCK IS THE SUBMISSION'S OWN `submitted_at`, NEVER `now()`. This is the same replay-clock
        // argument {@see SemanticValidator::validate()}'s `$now` parameter was added for in H17: a
        // `constraint: . <= today()` or a `relevant_expression` reading `today()` must be evaluated against
        // the day the response was filled, not the day someone fixed a typo in it. Under `now()`, correcting
        // one character in a year-old submission would fail validation rules that passed when it was
        // submitted — the answers would be judged against a calendar that has moved on without them.
        $clock = $submission->submitted_at?->toIso8601String();
        $semantic = $this->semantic->validate($version, $normalized, $submission->locale, $clock);
        if (! $semantic->passed()) {
            throw SubmissionValidationException::semantic($this->mapErrors($semantic->errors));
        }

        // ── ⚠️ NO STAGE 3.5, AND IT IS NOT AN OMISSION — RUNNING IT HERE BREAKS EVERY EDIT WITH MEDIA ────
        // {@see AttachmentReferenceValidator::validate()} exists to check refs a CLIENT just supplied against
        // STAGED files, and it asserts `attachable_type === 'form_field'` to do it. But
        // {@see SubmissionFinalizer::finalize()} RE-POINTS every referenced attachment to
        // `attachable_type = 'submission'` at submit. So on an edit those refs are — correctly — no longer
        // owned by a form_field, and calling the validator would raise `attachment_not_found` for every media
        // answer on every submission that has one. The first draft of this service did call it, and a test
        // with a real media answer is the only reason that is not shipping.
        //
        // Nothing is lost by skipping it: {@see self::mergeMedia()} takes the media answers from the STORED
        // document, so the only refs reaching this point are ones that already passed Stage 3.5 at submit and
        // whose files this submission already owns. There is no client-supplied ref left to validate. If
        // media editing is ever built — `docs/feature-backlog.md`, row 'Post-submission MEDIA editing' —
        // it needs its own check against submission-owned attachments, NOT this one.
        $final = array_merge($semantic->effectiveAnswers, $semantic->computed);
        $checksum = AnswersContentChecksum::of($normalized);

        $piiKeys = $this->piiAnswerKeys($fields, $sections);

        // Captured INSIDE the transaction (below) and read after it, the same by-reference shape
        // SubmissionReviewService::apply() uses for `$previousStatus`: the announcement needs to know whether
        // an approval was withdrawn, and the only place that is knowable is under the lock, before the
        // mutation. Reading `$submission->status` out here would read the caller's stale copy.
        $demoted = false;

        $fresh = DB::transaction(function () use ($submission, $version, $fields, $storedAnswers, $final, $checksum, $readChecksum, $editor, $piiKeys, &$demoted): Submission {
            $row = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            // The authoritative gate. Re-asserted under the lock because the pre-check above read the row
            // before it was held: a reviewer archiving this submission between the editor opening the page
            // and pressing Save is an ordinary race, not an exotic one.
            $this->assertEditable($row->status);

            // The SECOND half of the concurrency guard, and it closes a different window from the baseline
            // check above: that one catches a stale PAGE, this one catches a write that landed between this
            // request's own read and its lock. The status re-assert cannot stand in for either — a
            // concurrent edit does not move the status.
            //
            // Re-read under the lock on the SUBMISSIONS row rather than locking `submission_answers` too: the
            // 1:1 answer row is only ever written by a caller holding this lock, so serialising here is
            // enough, and taking a second lock would invert the order `SubmissionFinalizer` uses.
            $current = SubmissionAnswer::query()->where('submission_id', $row->id)->firstOrFail();
            if ($current->answers_content_checksum !== $readChecksum) {
                throw SubmissionEditException::concurrentlyModified();
            }

            $before = $this->statusSnapshot($row);

            // ── THE DEMOTION (user decision, 2026-08-08) ────────────────────────────────────────────────
            // Editing an APPROVED submission returns it to `under_review` and clears the approval stamps.
            // An approval is a statement that a reviewer read THESE answers and accepted them; changing the
            // answers underneath it makes that statement false while leaving it looking true, with
            // `validated_by` naming a person who never saw the new text. Demotion is what keeps the
            // approval record honest, and it puts the row back in the queue so a reviewer re-decides.
            //
            // It lives HERE and not in SubmissionReviewService, and it is ONE audit row rather than two,
            // because it is not an independent transition a reviewer chose — it is a consequence of this
            // edit, and a ledger showing "approval revoked" next to "answers edited" as separate events
            // invites the reading that someone revoked it deliberately.
            if ($row->status === SubmissionStatus::Approved) {
                $demoted = true;
                $row->status = SubmissionStatus::UnderReview;
                $row->validated_by = null;
                $row->validated_at = null;
                $row->finalized_at = null;
            }

            $row->save();

            // ⚠️ `$final`, THE RELEVANCE-PRUNED DOCUMENT — never `$normalized`. The first draft of this
            // service stored the un-pruned Stage-1 output here while handing `$final` to the projections
            // below, and a mutation check is the only reason that is not shipping: it makes the JSONB and the
            // typed index disagree about the same submission, and it RESURRECTS answers the original submit
            // deliberately pruned as irrelevant. Both finalize doors
            // ({@see SubmissionPipeline::persist()}, {@see SubmissionDraftService::promote()}) hand
            // `effectiveAnswers + computed` to {@see SubmissionFinalizer::finalize()}; storing anything else
            // here would make a submission's document shape depend on which path last wrote it.
            //
            // The content checksum stays over `$normalized`, which is NOT an inconsistency — it is what both
            // of those paths hash too (submit hashes its Stage-1 output; promote hashes the stored draft).
            // ⚠️ `attachment_refs` IS REWRITTEN, and leaving it alone was a real inconsistency. An edit cannot
            // ADD media, but relevance can REMOVE it: flip the yes/no that gates a photo field and Stage 3
            // prunes the media answer out of `$final`, leaving the JSONB — the declared source of truth —
            // saying the submission holds no file while the denormalised `attachment_refs` still listed it.
            // {@see SubmissionFinalizer::finalize()} keeps the two consistent by construction, re-deriving
            // the list from the exact answers it is about to write; this does the same, through the same
            // collaborator, so the two paths cannot drift.
            //
            // The displaced attachment ROW is deliberately left owned by the submission rather than deleted:
            // reversing the edit must restore a working reference, and a delete here would destroy a
            // respondent's file on a change that is meant to be reversible. Cleaning up genuinely orphaned
            // attachments is the media-editing vertical's problem — see `docs/feature-backlog.md`.
            SubmissionAnswer::query()->where('submission_id', $row->id)->update([
                'answers' => $final,
                'answers_content_checksum' => $checksum,
                'attachment_refs' => SubmissionFinalizer::collectAttachmentIds($fields, $final),
                'last_saved_at' => now(),
            ]);

            // ⚠️ REPLACE, never append. Both projections were INSERT-only before I9c; see
            // {@see SubmissionProjectionWriter} for why a value assertion cannot catch getting this wrong.
            $this->projections->rewrite($row, $version, $fields, $final);

            $this->audit->record(
                AuditEvent::Updated,
                'submission',
                (string) $row->getKey(),
                // Diffed against `$final`, the document actually written above — not `$normalized`. A
                // ledger that described a write different from the one that happened would be worse than no
                // ledger, and this is the shape that keeps the two identical by construction.
                old: [...$before, ...$this->answerDiff($storedAnswers, $final, keep: 'old')],
                new: [...$this->statusSnapshot($row), ...$this->answerDiff($storedAnswers, $final, keep: 'new')],
                // Explicit, never AuditLogger's Auth::id() fallback — the reasoning is written out at
                // SubmissionReviewService::apply(). Here it is doubly true: the demotion above clears
                // `validated_by`, so a ledger that guessed its actor could contradict the very row it
                // describes.
                actorId: (string) $editor->getKey(),
                piiAnswerKeys: $piiKeys,
            );

            return $row;
        });

        // POST-COMMIT, by call-site ordering — the convention the whole event pipeline uses
        // (technical-architecture.md §7.4). `DB::afterCommit` is verified never to fire under the suite's
        // uncommitted outer transaction, so it is not an option. The audit row above is the ledger and is
        // atomic with the change; this is the announcement, and it must not fire for an edit that rolled back.
        event(SubmissionUpdated::for($fresh, $editor, $demoted));

        return $fresh;
    }

    /**
     * @throws SubmissionEditException
     */
    private function assertEditable(SubmissionStatus $status): void
    {
        if (! in_array($status, self::EDITABLE, true)) {
            throw SubmissionEditException::illegalState($status);
        }
    }

    /**
     * Carry the STORED media answers through untouched, and drop any the editor sent.
     *
     * ⚠️ THIS IS THE SERVER HALF OF A UI DECISION, AND WITHOUT IT THE DECISION IS NOT ENFORCED. I9c renders
     * media and signature answers read-only (the attachment ownership re-point, the policy for displaced
     * files, `attachment_refs` rewriting for ADDED media and mid-edit scan-status gating are all out of
     * scope; `docs/feature-backlog.md` row 'Post-submission MEDIA editing' carries them). A disabled control is a suggestion: the PATCH body is a plain JSON
     * document an editor can post by hand, so a client that sends `photo: [{id: <someone else's file>}]`
     * would otherwise re-point an arbitrary attachment onto this submission. Taking the media keys from the
     * STORED document rather than the request makes the read-only-ness structural.
     *
     * Media may not be nested in a repeatable section (banned at publish, see {@see FieldType::isMedia()}),
     * so a top-level pass is complete — the same reasoning `projectGeo` relies on.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function mergeMedia(Collection $fields, array $stored, array $submitted): array
    {
        $merged = $submitted;

        foreach ($fields as $field) {
            if (! $field->field_type->isMedia()) {
                continue;
            }

            // Unset first, so a media key the editor invented for a field with no stored answer does not
            // survive as an empty-but-present entry.
            unset($merged[$field->key]);

            if (array_key_exists($field->key, $stored)) {
                $merged[$field->key] = $stored[$field->key];
            }
        }

        return $merged;
    }

    /**
     * The lifecycle attributes the demotion can move, snapshotted identically on both sides so one audit row
     * records the answer change and the status change together.
     *
     * Timestamps are ISO strings, never Carbon instances: a Carbon serializes into jsonb as
     * `{"date":…,"timezone_type":3,…}`, which no reader can parse. (Same note as
     * {@see SubmissionReviewService::snapshot()} — repeated rather than cross-referenced because the trap is
     * in the code here, not there.)
     *
     * @return array<string, mixed>
     */
    private function statusSnapshot(Submission $submission): array
    {
        return [
            'status' => $submission->status->value,
            'validated_by' => $submission->validated_by,
            'validated_at' => $submission->validated_at?->toIso8601String(),
            'finalized_at' => $submission->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * The CHANGED answer keys, one side of them, flattened to `answers.<key>` entries.
     *
     * ── ⚠️ THE `answers.` PREFIX IS AT THE TOP LEVEL, AND THAT IS THE WHOLE TRICK ────────────────────────
     * {@see AuditRedactor::redact()} matches its sensitive-key list against the payload's TOP LEVEL ONLY — a
     * flat `array_key_exists($key, $values)` loop, no recursion. Nest these under an `answers` key and
     * `$piiAnswerKeys` silently matches nothing: the row still writes, the ledger still looks populated,
     * `redacted_fields` comes back `null`, and every PII answer sits in the audit table in clear text. It
     * would look wired up and be a no-op. {@see self::piiAnswerKeys()} produces the prefixed names to match.
     *
     * ── CHANGED KEYS ONLY, not a full document snapshot ──────────────────────────────────────────────────
     * A full before/after would put every answer of every edited submission into `audits` twice, and the
     * question a reader brings to an edit row is "what changed", which a full snapshot buries. The
     * consequence is stated so it is not discovered: an audit row for an edit does NOT reconstruct the
     * complete document at that point in time. `submission_answers` is the current document and this ledger
     * is the diff log; reconstructing a historical state means replaying the diffs.
     *
     * A key added by the edit appears only on the `new` side, and one cleared only on the `old` side —
     * `array_key_exists` rather than a null comparison, so "answered null" and "not answered" stay distinct.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  'old'|'new'  $keep
     * @return array<string, mixed>
     */
    private function answerDiff(array $old, array $new, string $keep): array
    {
        $diff = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if (array_key_exists($key, $old) === array_key_exists($key, $new) && self::sameAnswer($before, $after)) {
                continue;
            }

            $side = $keep === 'old' ? $old : $new;

            if (array_key_exists($key, $side)) {
                $diff['answers.'.$key] = $side[$key];
            }
        }

        return $diff;
    }

    /**
     * Are two answer values the same, ignoring KEY ORDER inside object-valued ones?
     *
     * ⚠️ A BARE `===` REPORTS PHANTOM CHANGES, and the noise lands in the one table `answerDiff()` tells
     * readers to replay. PHP's `===` on arrays requires identical key ORDER, but the order of an
     * object-valued answer is the CLIENT's: `StructuralAnswerNormalizer::normalizeRepeatSection()` builds
     * each instance in the order the keys arrived, so a repeat group serialised as `{phone, name}` instead of
     * `{name, phone}` is byte-identical in content and `!==` in PHP. Every edit to such a form would then
     * claim its repeat groups and matrix grids changed when nobody touched them.
     *
     * Recursive ksort then `===` — NOT `==`, which is loose on the values too (`'1' == 1`, `'' == null`) and
     * would hide a real correction from `'0'` to `''`. Order is the only thing being normalised away.
     */
    private static function sameAnswer(mixed $before, mixed $after): bool
    {
        if (is_array($before) && is_array($after)) {
            self::ksortRecursive($before);
            self::ksortRecursive($after);
        }

        return $before === $after;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksortRecursive($item);
            }
        }
    }

    /**
     * The `answers.<key>` names of every field flagged `is_pii` or `is_sensitive`, for
     * {@see AuditLogger::record()}'s `$piiAnswerKeys` — a parameter that has existed since H4 with **zero
     * callers anywhere in `app/`**. I9c is its first consumer, which is why the prefix contract it depends
     * on ({@see self::answerDiff()}) had never been exercised.
     *
     * ⚠️ A REPEAT-GROUP MEMBER CANNOT BE REDACTED INDIVIDUALLY. Its answer lives at
     * `answers[section][n][field]`, and the redactor placeholders whole top-level values — so a PII field
     * inside a repeatable section causes the SECTION key to be redacted instead, taking its non-PII siblings
     * with it. That is the conservative direction (over-redaction, never under), and it is the only option
     * without a recursive redactor, which would be a change to a shipped compliance component made from
     * inside an answer-editing increment.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return list<string>
     */
    private function piiAnswerKeys(Collection $fields, Collection $sections): array
    {
        // Indexed from the collection already loaded by edit(), never `$field->section` per field — that is
        // a lazy load inside a loop over every field on the form.
        $repeatable = $sections
            ->filter(static fn (FormSection $s): bool => $s->is_repeatable)
            ->keyBy(static fn (FormSection $s): string => (string) $s->id);

        /** @var array<string, true> $keys */
        $keys = [];

        foreach ($fields as $field) {
            if (! $field->is_pii && ! $field->is_sensitive) {
                continue;
            }

            // A field in a repeatable section is keyed under its SECTION at the top level; redacting the
            // field's own key would match nothing at all, which is the silent-no-op failure again.
            $section = $field->form_section_id === null ? null : $repeatable->get($field->form_section_id);

            $keys['answers.'.($section instanceof FormSection ? $section->key : $field->key)] = true;
        }

        return array_keys($keys);
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
