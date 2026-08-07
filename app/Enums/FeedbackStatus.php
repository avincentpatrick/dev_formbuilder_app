<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-app feedback triage state (PRD Feature #11 / data-dictionary §21). The tenant-side submit path
 * (Increment C3) only ever creates rows as `new`; the reviewed/resolved/wont_fix transitions belong to
 * the platform support console (built in I7a).
 *
 * ⚠️ **`feedback_reports.status` carries NO CHECK constraint** — unlike `audits.event`, whose constraint
 * is generated from {@see AuditEvent::values()}. This enum is therefore the ONLY guard on the column: a
 * literal string written anywhere in the codebase persists silently and forever, and no test, migration
 * or database error will ever surface it. Always write `FeedbackStatus::X->value`.
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';

    /** Human label for the console filter, the tenant-side list, and the audit payload (single source). */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Reviewed => 'Reviewed',
            self::Resolved => 'Resolved',
            self::WontFix => "Won't fix",
        };
    }

    /**
     * Whether this status closes the report — i.e. whether reaching it stamps `resolved_at`/`resolved_by`.
     *
     * `wont_fix` is terminal exactly as `resolved` is, and it stamps the same two columns: data-dictionary
     * §21 describes `resolved_by` as "a platform support-team member", not "the person who fixed it".
     * Declining a report IS handling it, and the ledger of who handled what must not have a hole where the
     * declines were.
     */
    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::WontFix;
    }

    /**
     * The transitions the support console offers from here — the single source the server guard AND the
     * page's offered-actions map both read, mirroring how `Pages/submissions/Show.vue` mirrors
     * {@see App\Services\Submissions\SubmissionReviewService}.
     *
     * Read as a graph: `new` is triage (acknowledge, or decline outright); `reviewed` is in-hand (close it
     * either way); and **both terminal states can be re-opened back to `reviewed`, never to `new`**. That
     * asymmetry is deliberate — `new` means "nobody has looked at this yet", which stops being true the
     * moment somebody has, and a support queue that can lie about its own untriaged count is worse than
     * one that cannot go backwards.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Reviewed, self::WontFix],
            self::Reviewed => [self::Resolved, self::WontFix],
            self::Resolved, self::WontFix => [self::Reviewed],
        };
    }

    /** Guard for {@see App\Services\Feedback\FeedbackService::transition()} — never trust the client's verb. */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), strict: true);
    }

    /**
     * The filter catalog for the console + tenant list, in lifecycle order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
