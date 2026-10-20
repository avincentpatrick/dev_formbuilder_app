<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a comment in a feedback thread did, beyond saying something (M179, `R-129c44c3`, `D151`).
 *
 * `feedback_comments.action` is NULL on an ordinary comment or reply. It is set on the comment a tester's move
 * writes, so the thread itself tells the story the status column can only summarise:
 *
 * - `confirmed` — the tester pressed **It works** (a Resolved report became Confirmed). The body may be empty.
 * - `reopened` — the tester pressed **Still a problem** (a Resolved or Confirmed report went back to Ongoing).
 *   The body is required: it says what is still wrong, and it is what the next session's pull shows as
 *   REOPENED.
 *
 * `feedback_comments_action_check` is generated from {@see self::values()}.
 */
enum FeedbackCommentAction: string
{
    case Reopened = 'reopened';
    case Confirmed = 'confirmed';

    /**
     * Every stored value, in declaration order — the source of `feedback_comments_action_check`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
