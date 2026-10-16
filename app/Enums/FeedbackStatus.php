<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Admin\SuperAdminService;

/**
 * In-app feedback triage state (PRD Feature #11 / data-dictionary §21). The tenant-side submit path
 * (Increment C3) only ever creates rows as `new`; every other move belongs to the platform support console
 * (I7a) and, since M175, to the platform support API that reads and writes through the same service.
 *
 * ── THE WORDS CHANGED, THE STORED VALUES DID NOT (M175, `R-9bfd832e`, `D139`) ─────────────────────────────
 * The testers asked for **New · Ongoing · Resolved · Declined**. `Ongoing` is stored as `reviewed` and
 * `Declined` as `wont_fix` — the I7a values — so no existing row was rewritten (`D137`: never touch staging
 * data). Read a raw row with this map in mind; the cases carry the new names so the code says what the
 * screen says.
 *
 * `feedback_reports.status` carries `feedback_reports_status_check` since M175, generated from
 * {@see self::values()} (until then this enum was the column's only guard). Always write
 * `FeedbackStatus::X->value`, never a literal.
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case Ongoing = 'reviewed';
    case Resolved = 'resolved';
    case Declined = 'wont_fix';

    /** Human label for the console, both tenant lists, the pull digest and the audit payload (single source). */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Ongoing => 'Ongoing',
            self::Resolved => 'Resolved',
            self::Declined => 'Declined',
        };
    }

    /**
     * Whether this status closes the report — i.e. whether reaching it stamps `resolved_at`/`resolved_by` and
     * tells the reporter (M175).
     *
     * `Declined` is terminal exactly as `Resolved` is, and it stamps the same two columns: data-dictionary
     * §21 describes `resolved_by` as "a platform support-team member", not "the person who fixed it".
     * Declining a report IS handling it, and the ledger of who handled what must not have a hole where the
     * declines were.
     */
    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Declined;
    }

    /**
     * The moves offered from here — the single source the server guard AND every client's offered-actions
     * list both read.
     *
     * Read as a graph: `new` is triage (take it on, fix it outright, or decline it — M175 added the straight
     * route to Resolved, for a fix that landed before anyone marked it Ongoing); `ongoing` is in hand (close
     * it either way); and **both closed states re-open to Ongoing, never to New**. That asymmetry is I7a's
     * and deliberate — `new` means "nobody has looked at this yet", which stops being true the moment somebody
     * has, and a support queue that can lie about its own untriaged count is worse than one that cannot go
     * backwards. Resolved ↔ Declined goes through Ongoing for the same reason: the reporter is told on each
     * close, and a close that silently flips to the other would tell them twice without saying it re-opened.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Ongoing, self::Resolved, self::Declined],
            self::Ongoing => [self::Resolved, self::Declined],
            self::Resolved, self::Declined => [self::Ongoing],
        };
    }

    /** Guard for {@see SuperAdminService::transitionFeedback()} — never trust the client's verb. */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), strict: true);
    }

    /**
     * Every stored value, in declaration order — the source of `feedback_reports_status_check`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
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
