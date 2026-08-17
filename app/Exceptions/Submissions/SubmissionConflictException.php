<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

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

    /** The stable API envelope code (bootstrap/app.php maps every cause to 409 with this code). */
    public function code(): string
    {
        return $this->apiCode;
    }
}
