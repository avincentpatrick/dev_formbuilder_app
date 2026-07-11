<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use App\Enums\SubmissionStatus;
use RuntimeException;

/**
 * An illegal submission review-lifecycle transition (Increment F7) — e.g. approving an already-archived
 * submission, or returning a draft that never entered the review queue. Distinct from the pipeline's
 * {@see SubmissionException} (write-time state) and {@see SubmissionValidationException} (per-field). No
 * `render()`: the controller catches it and maps it to an error toast, mirroring the Forms controllers.
 */
final class SubmissionReviewException extends RuntimeException
{
    public static function illegalTransition(SubmissionStatus $from, string $action): self
    {
        return new self("Cannot {$action} a submission that is {$from->label()}.");
    }
}
