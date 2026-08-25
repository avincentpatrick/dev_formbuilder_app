<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use App\Http\Controllers\Tenant\SubmissionDraftController;
use App\Services\Submissions\SubmissionDraftService;
use RuntimeException;

/**
 * A Submission Pipeline CONTENT conflict (Increment G8c, docs/offline-first-sync-design.md §5): the same
 * `client_submission_uuid` was already persisted with materially DIFFERENT answers than the incoming replay
 * carries. Unlike an idempotent duplicate (byte-identical content → a 200 no-op), this is a genuine
 * concurrent edit — e.g. the local draft was changed after an earlier sync succeeded, or another channel
 * (an authenticated encoder) already corrected the same record.
 *
 * Distinct from {@see SubmissionException} (a version-state/drift violation → `submission_version_superseded`)
 * so the two 409 causes carry distinct API codes. No `render()`: HTTP mapping is centralised in
 * bootstrap/app.php, which reads {@see code()} to pick the envelope code, mirroring the sibling submission
 * exceptions.
 *
 * The {@see draftAlreadyFinalized()} cause (Increment H9a) is a save-vs-promote race: a draft write arrived
 * for a `client_submission_uuid` whose row is no longer a draft (already promoted to `submitted`). H9a reused
 * the `submission_conflict` envelope (the draft substrate shipped no HTTP route); now that H9b exposes the
 * draft routes it carries its own `draft_already_finalized` code so a client can tell "this draft is already
 * submitted" from a genuine content conflict.
 *
 * The {@see draftConcurrentlyModified()} cause (Increment P3a) is a DRAFT lost update, and since Increment
 * M12 it has two raisers rather than one — the draft save and the draft PROMOTE. See its own docblock.
 *
 * The {@see clientUuidClaimed()} cause (Increment M11) is the fourth and is about ENTITLEMENT rather than
 * content or timing: the uuid is already spent in this tenant on a row outside the caller's form/author
 * scope. It SHARED `submission_conflict` with {@see contentConflict()} until Increment M14 gave it
 * `submission_uuid_claimed` — the reversal is argued in its own docblock and turns on a premise M14 removed.
 */
final class SubmissionConflictException extends RuntimeException
{
    private function __construct(string $message, private readonly string $apiCode)
    {
        parent::__construct($message);
    }

    public static function contentConflict(): self
    {
        return new self(
            'This response conflicts with a copy already saved for the same submission.',
            'submission_conflict',
        );
    }

    /**
     * A DRAFT lost update (Increment P3a): the draft's stored answers moved between the saving device
     * reading them and this save arriving — i.e. another device wrote to the same draft in between.
     *
     * ⚠️ THIS IS NOT THE SUSPENDED 409, AND THE DISTINCTION IS THE WHOLE POINT.
     * {@see contentConflict()} compares the INCOMING answers against the stored ones and is correctly
     * suspended for drafts — a draft's content changes on every autosave, so that comparison would refuse
     * every keystroke. This compares the BASE the device edited from against the stored state, so it is
     * silent for the ordinary same-device save (the base always matches) and fires only when a second
     * device has written. The suspension is therefore narrowed, not reversed.
     *
     * Why it must refuse rather than merge: the draft write is a whole-document replace, so accepting a
     * stale base silently reverts every answer the other device saved. Merging the two documents is
     * CRDT-shaped work, deferred on the record in five documents
     * (`docs/offline-first-sync-design.md` §9). Refusing costs one device a reload; accepting costs the
     * other device its answers, with nothing anywhere saying so.
     *
     * The message names the action, because a respondent told only "conflict" will press Save again into
     * the same refusal — the copy they are looking at is stale, so re-reading the draft is the only move
     * that helps.
     *
     * ⚠️ TWO RAISERS SINCE INCREMENT M12, AND THE SECOND IS THE DOOR P3a DID NOT REACH.
     * {@see SubmissionDraftService::updateDraft()} raises it for a save whose client-supplied base has moved;
     * {@see SubmissionDraftService::promote()} raises it for a save that lands between promotion's own read
     * of the answer document and the lock it finalizes under — the finalize being a whole-document replace,
     * and the row being `submitted` afterwards, so that loss is the one no later save can undo.
     *
     * ⚠️ ONE CODE, ONE WORDING, BOTH DOORS — DELIBERATELY, AND FOR M11'S RECORDED REASON. The cause is the
     * same sentence to a client (*another device wrote to this draft*) and the remedy is the same act
     * (*re-read the draft, keep the uuid*), so a fifth factory would oblige every client to learn a second
     * name for one refusal. Both HTTP arms already render this cause — bootstrap/app.php maps it to a 409
     * for `/api/v1` and to a toast for the web — so M12 moved no client contract and `openapi.json` stayed
     * byte-identical.
     */
    public static function draftConcurrentlyModified(): self
    {
        return new self(
            'This draft was updated on another device. Reload it to pick up the newer answers before saving again.',
            'draft_conflict',
        );
    }

    public static function draftAlreadyFinalized(): self
    {
        return new self(
            'This draft has already been submitted and can no longer be saved as a draft.',
            'draft_already_finalized',
        );
    }

    /**
     * Increment M11 — the `client_submission_uuid` is already spent inside this tenant on a row the caller
     * cannot resolve: a different form, a different author, or a soft-deleted tombstone still holding the
     * index entry.
     *
     * ⚠️ IT CARRIES ITS OWN CODE SINCE INCREMENT M14, AND THE REVERSAL IS THE POINT RATHER THAN A CHANGE OF
     * MIND. M11 shared `submission_conflict` with {@see contentConflict()} on one stated ground: *"the guest
     * runtime folds all 409s alike today (its own filed row), so a new code would buy nothing and cost a
     * contract."* That was true, and M14 is the row it names — the guest runtime now reads `error.code` on
     * every 409 and picks a remedy from it. With the premise gone the conclusion goes with it: a shared code
     * obliges every client to tell two causes apart by STRING-MATCHING A HUMAN-FACING SENTENCE, which this
     * class already warns is the trap that makes a test pass for the wrong cause.
     *
     * ⚠️ AND THE TWO CAUSES ARE NOT THE SAME REFUSAL, WHICH IS EASIEST TO SEE ON THE DRAFT CHANNEL.
     * {@see contentConflict()} is deliberately SUSPENDED for drafts — a draft's content changes on every
     * autosave — so on `POST /api/v1/public/f/{shareToken}/draft` the shared code could only ever have meant
     * this cause, and a client had no way to know that. The remedies differ too: a content conflict is
     * answered by reviewing the copy that already exists, this one by minting a fresh identifier, because
     * the answers were never in dispute — the identifier was.
     *
     * Distinct from {@see contentConflict()} in cause AND, since M14, in envelope: that one is "this uuid is
     * yours and the content moved", this one is "this uuid was never yours to write to".
     *
     * ⚠️ THE WORDING GENERALISED FROM *"draft identifier"* WHEN THE CAUSE STOPPED BEING DRAFT-ONLY. The
     * draft channel ({@see SubmissionDraftController::resolveTarget()}, which has returned exactly this
     * refusal for exactly this cause since I9b) spelled the sentence inline; it now reads it off this
     * factory, and the submit channels
     * raise the same cause — where "draft" would be simply untrue. One factory, one wording, three channels.
     * ⚠️ THE MESSAGE IS NO LONGER THE ONLY THING SEPARATING THIS FROM {@see contentConflict()} ON THE WIRE,
     * but keep asserting it anyway: it is what a respondent and an integrator actually read, and M11's
     * mutation pass found the first draft of its own tests passing for either cause because they asserted
     * the exception CLASS alone. Assert the code AND the message.
     */
    public static function clientUuidClaimed(): self
    {
        return new self(
            'This submission identifier already belongs to another response.',
            'submission_uuid_claimed',
        );
    }

    /** The stable API envelope code (bootstrap/app.php maps every cause to 409 with this code). */
    public function code(): string
    {
        return $this->apiCode;
    }
}
