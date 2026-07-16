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
 * bootstrap/app.php (→ 409 `submission_conflict`), mirroring the sibling submission exceptions.
 */
final class SubmissionConflictException extends RuntimeException
{
    public static function contentConflict(): self
    {
        return new self('This response conflicts with a copy already saved for the same submission.');
    }
}
