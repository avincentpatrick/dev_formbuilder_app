<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Admin\SuperAdminService;

/**
 * In-app feedback triage state (PRD Feature #11 / data-dictionary §21). The tenant-side submit path
 * (Increment C3) only ever creates rows as `new`. Every support move belongs to the platform support console
 * (I7a) and, since M175, to the platform support API that reads and writes through the same service. Since
 * M179 (`D151`) a member has moves of their own on a fixed report — **It works** and **Still a problem** —
 * which is why the graph below takes the side that is acting ({@see FeedbackParty}).
 *
 * ── THE WORDS CHANGED, THE STORED VALUES DID NOT (M175, `R-9bfd832e`, `D139`) ─────────────────────────────
 * The testers asked for **New · Ongoing · Resolved · Declined**. `Ongoing` is stored as `reviewed` and
 * `Declined` as `wont_fix` — the I7a values — so no existing row was rewritten (`D137`: never touch staging
 * data). Read a raw row with this map in mind; the cases carry the new names so the code says what the
 * screen says.
 *
 * `feedback_reports.status` carries `feedback_reports_status_check` since M175, generated from
 * {@see self::values()} (until then this enum was the column's only guard), and rebuilt wider for `confirmed`
 * by M179's `000135`. Always write `FeedbackStatus::X->value`, never a literal.
 *
 * ── CONFIRMED IS THE TESTER'S, AND IT CLOSES NOTHING ON THE SUPPORT SIDE (M179, `D151`) ────────────────────
 * `confirmed` ("Confirmed working") is reached only by a member pressing **It works** on a Resolved report.
 * It keeps who resolved the report and when, and tells no one: {@see self::stampsResolution()} is still
 * Resolved and Declined alone. Declared after Resolved so the filter catalog reads in lifecycle order.
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case Ongoing = 'reviewed';
    case Resolved = 'resolved';
    case Confirmed = 'confirmed';
    case Declined = 'wont_fix';

    /** Human label for the console, both tenant lists, the pull digest and the audit payload (single source). */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Ongoing => 'Ongoing',
            self::Resolved => 'Resolved',
            self::Confirmed => 'Confirmed working',
            self::Declined => 'Declined',
        };
    }

    /**
     * Whether reaching this status stamps `resolved_at`/`resolved_by` and tells the reporter (M175) — the
     * support team's close.
     *
     * `Declined` stamps exactly as `Resolved` does: data-dictionary §21 describes `resolved_by` as "a platform
     * support-team member", not "the person who fixed it". Declining a report IS handling it, and the ledger
     * of who handled what must not have a hole where the declines were.
     *
     * ⛔ NOT `Confirmed` (M179). A tester's **It works** stamps `confirmed_*` and leaves `resolved_*` alone; run
     * through this predicate it would overwrite the support member who resolved the report with the tester,
     * and mail the reporter "your feedback was updated" about their own click. This predicate was called
     * `isTerminal()` until M179, and was renamed rather than widened so no caller could inherit a new meaning
     * under the old name.
     */
    public function stampsResolution(): bool
    {
        return $this === self::Resolved || $this === self::Declined;
    }

    /**
     * Whether the testers still have something to do or wait for here — the "N open" count on the
     * Testing-round comments tab (M179, `D150`). Confirmed and Declined are done; a Resolved report is open,
     * because it waits for a tester's **It works**.
     */
    public function isOpenForTesters(): bool
    {
        return $this !== self::Confirmed && $this !== self::Declined;
    }

    /**
     * The moves one side may make from here — the single source the server guard AND every client's
     * offered-actions list both read. The side has no default (M179): every caller says which door it is.
     *
     * **Support** — read as a graph: `new` is triage (take it on, fix it outright, or decline it — M175 added
     * the straight route to Resolved, for a fix that landed before anyone marked it Ongoing); `ongoing` is in
     * hand (close it either way); and **every closed state re-opens to Ongoing, never to New**. That asymmetry
     * is I7a's and deliberate — `new` means "nobody has looked at this yet", which stops being true the moment
     * somebody has, and a support queue that can lie about its own untriaged count is worse than one that
     * cannot go backwards. Resolved ↔ Declined goes through Ongoing for the same reason: the reporter is told
     * on each close, and a close that silently flips to the other would tell them twice without saying it
     * re-opened. Support re-opens a Confirmed report too — for a regression found on our side — but **never
     * reaches Confirmed**: the console's offered actions ARE this graph (`FeedbackConsolePresenter`), so an edge
     * there would put "Mark Confirmed working" in front of the support team.
     *
     * **Member** (M179, `D151`) — only after a fix: **It works** (Resolved → Confirmed) and **Still a
     * problem** (Resolved or Confirmed → Ongoing, with a comment the lifecycle requires). Nothing else: a
     * member never triages, never resolves, never declines, and never re-opens a Declined report.
     *
     * @return list<self>
     */
    public function allowedTransitions(FeedbackParty $by): array
    {
        if ($by === FeedbackParty::Member) {
            return match ($this) {
                self::Resolved => [self::Confirmed, self::Ongoing],
                self::Confirmed => [self::Ongoing],
                self::New, self::Ongoing, self::Declined => [],
            };
        }

        return match ($this) {
            self::New => [self::Ongoing, self::Resolved, self::Declined],
            self::Ongoing => [self::Resolved, self::Declined],
            self::Resolved, self::Confirmed, self::Declined => [self::Ongoing],
        };
    }

    /** Guard for every move — {@see SuperAdminService::transitionFeedback()} and the testers' door. Never trust the client's verb. */
    public function canTransitionTo(self $to, FeedbackParty $by): bool
    {
        return in_array($to, $this->allowedTransitions($by), strict: true);
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
