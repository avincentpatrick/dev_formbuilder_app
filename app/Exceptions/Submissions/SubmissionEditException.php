<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Tenant\SubmissionEditController;
use App\Http\Controllers\Tenant\SubmissionReviewController;
use RuntimeException;

/**
 * A refused post-submission answer edit (Increment I9c) — the submission is in a state whose answers may not
 * be corrected. Sibling of {@see SubmissionReviewException}, and deliberately NOT that class: its docblock
 * scopes it to "an illegal review-lifecycle transition", and an edit is a mutation of the answer document
 * that only sometimes moves the lifecycle. Two exception types let the two controllers catch what they
 * actually handle instead of sharing one and hoping.
 *
 * No `render()`: {@see SubmissionEditController} catches it and maps it to an
 * error toast, mirroring {@see SubmissionReviewController}.
 */
final class SubmissionEditException extends RuntimeException
{
    /**
     * The two refusals carry different reasons and say so, because "this cannot be edited" with no cause is
     * the message that generates a support ticket.
     *
     * `draft` is redirected rather than explained — a draft's answers ARE editable, through I9b's resume
     * page, so the honest response is to send the editor there. That branch lives in the controller, which
     * is the layer that can redirect; this exception never sees it.
     */
    public static function illegalState(SubmissionStatus $status): self
    {
        return new self(match ($status) {
            // ⚠️ THE CAPACITY REASON, spelled out rather than reduced to "not allowed". A screened-out
            // response consumes no `max_responses` slot ({@see \App\Models\Submission::scopeConsumesCapacity()},
            // I9a). Editing answers INTO one would leave a row whose state means "was shown no questions"
            // holding real answers, or force a silent flip to `submitted` that consumes a paid slot on a form
            // that may already be at its cap. Widening this needs a `capacity_consumed_at` column, not a
            // fifth entry in SubmissionAnswerEditService::EDITABLE.
            SubmissionStatus::ScreenedOut => 'This response was screened out and has no answers to correct.',
            // Archival is a retention act, not a workflow parking space. The row is intentionally terminal.
            SubmissionStatus::Archived => 'This submission has been archived and can no longer be edited.',
            default => "Cannot edit a submission that is {$status->label()}.",
        });
    }

    /**
     * Somebody else's edit landed between this editor loading the page and pressing Save.
     *
     * The alternative is a SILENT lost update: the write is a whole-document replace, so accepting this would
     * revert every field the other editor corrected, with no error, and leave an audit row that describes a
     * change to one key while saying nothing about undoing theirs. Refusing costs this editor a retype of one
     * form; accepting costs the other editor their work and the ledger its accuracy.
     *
     * The message says what to DO, because "conflict" alone leaves someone clicking Save again into the same
     * refusal — the page they are looking at is stale, so reloading is the only move that helps.
     */
    public static function concurrentlyModified(): self
    {
        return new self(
            'Someone else changed this submission while you were editing it. '
            .'Reload the page to see their changes, then make your correction again.'
        );
    }
}
