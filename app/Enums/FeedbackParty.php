<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which side of a feedback report acts (M179, `R-129c44c3`, `D151`).
 *
 * The two sides move a report through different graphs — {@see FeedbackStatus::allowedTransitions()} takes the
 * side and has no default, so every caller says which door it is — and both write to the same thread, where
 * the side is `feedback_comments.author_kind`.
 *
 * - `member` — anyone in the workspace who may see the report: the reporter on their own report, any member
 *   on a relayed one, an Owner or Admin on any. A member may say **It works** or **Still a problem** on a
 *   fixed report, and comment.
 * - `support` — the platform support team, through the console or the support API. Support moves a report
 *   through triage, marks it Resolved or Declined, and replies; it never marks a report Confirmed.
 */
enum FeedbackParty: string
{
    case Member = 'member';
    case Support = 'support';

    /**
     * Every stored value, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
