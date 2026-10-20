<?php

declare(strict_types=1);

namespace App\Exceptions\Feedback;

use App\Enums\FeedbackStatus;
use RuntimeException;

/**
 * A move the feedback lifecycle refused (M179, `R-129c44c3`) — judged against the row as it IS, read under a
 * lock, never against the model a caller was holding. The support door maps it to its own
 * `SuperAdminException` (the console's error bag, the API's 422 `invalid_transition`); the testers' door turns
 * it into a message on the page.
 */
final class FeedbackLifecycleException extends RuntimeException
{
    public static function refused(FeedbackStatus $from, FeedbackStatus $to): self
    {
        if ($from === FeedbackStatus::Confirmed && $to === FeedbackStatus::Confirmed) {
            return new self('Someone has already confirmed this fix.');
        }

        return new self("A feedback report cannot go from {$from->label()} to {$to->label()}.");
    }
}
