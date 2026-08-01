<?php

declare(strict_types=1);

namespace App\Enums;

use App\Notifications\Submissions\SubmissionPdfReadyNotification;

/**
 * How a submission-PDF request ended (Increment H17) — the payload of
 * {@see SubmissionPdfReadyNotification}, which is the ONLY channel that tells the requesting user
 * anything at all.
 *
 * That is why failure is modelled rather than left to a silent dead-lettered job. There is no
 * notification bell in this application (no `notifications` table, no `NotificationType` enum, no
 * polling, `BROADCAST_CONNECTION=log`), so a user who clicks "Generate PDF" and hears nothing has
 * no way to distinguish "still working" from "failed an hour ago". Every terminal state gets an
 * email.
 *
 * A backed enum rather than three notification classes or a bare string: `scripts/job-payload-lint.php`
 * R3 admits `App\Enums\` types in a queued payload, so this crosses the queue boundary safely, and
 * a new outcome becomes a `match` arm the compiler asks about rather than a string someone typos.
 */
enum SubmissionPdfOutcome: string
{
    /** The document was generated and stored; the notification carries a download link. */
    case Ready = 'ready';

    /** The tenant's `storage_bytes` allowance could not absorb the document. Not a bug — a limit. */
    case QuotaExceeded = 'quota_exceeded';

    /** Anything else: a render fault, or a malware verdict on our own output. */
    case Failed = 'failed';

    /** The email subject line. */
    public function subject(string $formTitle): string
    {
        return match ($this) {
            self::Ready => "Your PDF for “{$formTitle}” is ready",
            self::QuotaExceeded, self::Failed => "We could not generate your PDF for “{$formTitle}”",
        };
    }

    /**
     * The explanatory line. Says what happened AND what to do — a dead end with no next step is
     * the failure mode of every "something went wrong" email.
     */
    public function body(): string
    {
        return match ($this) {
            self::Ready => 'You can download it using the button below. The link opens the submission record in your workspace, so you will need to be signed in.',
            self::QuotaExceeded => 'Your workspace has reached its storage limit, so the document was discarded rather than saved. Free up storage or upgrade your plan, then try again.',
            self::Failed => 'Something went wrong while building the document and nothing was saved. Please try again; if it keeps happening, contact support with the submission reference below.',
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }
}
