<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\BadgeKey;
use Carbon\CarbonImmutable;

/**
 * One member's whole badge catalog, split into what they hold and what they are working toward
 * (gamification-design.md §7, ADR-0020 §D9) — Increment K1e.
 *
 * The {@see Leaderboard} / {@see MemberStreak} arrangement for the third time, and for their reason:
 * {@see BadgeShelfService} owns the SQL and this owns the *rule*, so the ordering — the part with ties and
 * an empty case in it — is a pure function that can be exercised exhaustively without a database.
 *
 * ── ⚠️ TWO LISTS, NOT ONE SORTED LIST, AND THE SPLIT IS THE DESIGN RATHER THAN A CONVENIENCE ────────────
 * A single ranked catalog would need one comparator spanning two incomparable things: a date somebody
 * earned something, and a distance to something they have not. Any such comparator has to invent an
 * exchange rate between them — "is a badge earned in March ahead of one you are two responses away from?"
 * — and whatever it answers, half the readers disagree. Splitting the question is what lets each half
 * carry an honest rule of its own, and it is also the shape the surface renders: two sections with two
 * headings, never one list a reader has to decode.
 *
 * ⚠️ **NEITHER LIST IS THE CATALOG ORDER, AND `BadgeKey::cases()` IS STILL THE TIE-BREAK IN BOTH.** Falling
 * through to declaration order rather than to nothing is what keeps two identical requests from
 * reshuffling — the {@see Leaderboard::fromRoster()} argument, where leaving the last comparison to
 * whatever the database returned would make a stable-looking list move under the reader.
 */
final readonly class BadgeShelf
{
    private function __construct(
        /** @var list<BadgeStanding> held, most recently earned first */
        public array $earned,
        /** @var list<BadgeStanding> not held, nearest to its threshold first */
        public array $inProgress,
    ) {}

    /**
     * Assemble the catalog for one member.
     *
     * ⚠️ **BUILT BY WALKING {@see BadgeKey::cases()}, NEVER BY WALKING THE AWARD ROWS.** The enum is the
     * catalog; the ledger only says which of it somebody holds. Driving the loop from the rows would make
     * an unearned badge *absent* rather than *at zero* — and a surface that cannot name what you have not
     * earned yet has stopped being an achievements surface. It would also hide a retired key's rows instead
     * of surfacing them, which is a different bug wearing the same clothes.
     *
     * @param  array<string, CarbonImmutable>  $earnedOn  badge value => when, from `badge_awards`
     * @param  array<string, int>  $countsByRule  rule value => how many awards, from `point_awards`
     */
    public static function assemble(array $earnedOn, array $countsByRule): self
    {
        $earned = [];
        $inProgress = [];

        foreach (BadgeKey::cases() as $badge) {
            // `?? 0` rather than requiring the map to cover the catalog, because a member who has never
            // performed an act simply has no row for its rule — the ordinary case, not an error. Same
            // reading Leaderboard::fromRoster() gives its two maps.
            $progress = $countsByRule[$badge->rule()->value] ?? 0;
            $on = $earnedOn[$badge->value] ?? null;

            if ($on !== null) {
                $earned[] = BadgeStanding::earned($badge, $on, $progress);

                continue;
            }

            $inProgress[] = BadgeStanding::unearned($badge, $progress);
        }

        return new self(
            earned: self::mostRecentFirst($earned),
            inProgress: self::nearestFirst($inProgress),
        );
    }

    /** The shelf off-tenant, or for a member with nothing at all. See {@see BadgeShelfService}. */
    public static function none(): self
    {
        return new self(earned: [], inProgress: []);
    }

    /**
     * Held badges, newest first.
     *
     * Newest rather than the catalog's own order because the question this section answers is *"what have I
     * done lately"*; a fixed order would bury a badge earned this morning under one earned on the day the
     * member joined, which — {@see BadgeKey::Welcome} being unmissable — is every member's first row.
     *
     * ⚠️ **TIES ARE REAL HERE AND ARE NOT A THEORETICAL CONCERN.** {@see BadgeAwarder} stamps every badge it
     * creates in one call with the **triggering award's** timestamp, so a single act crossing two tiers
     * writes two rows sharing an instant to the microsecond. `BadgeKey::forRule()` already orders those
     * cheapest-first when awarding them; falling through to declaration order here reproduces that, so the
     * pair reads in the order they were earned rather than in whichever order the rows came back.
     *
     * @param  list<BadgeStanding>  $badges  in catalog order
     * @return list<BadgeStanding>
     */
    private static function mostRecentFirst(array $badges): array
    {
        usort($badges, static function (BadgeStanding $a, BadgeStanding $b): int {
            return $b->earnedOn <=> $a->earnedOn
                ?: self::catalogPosition($a->badge) <=> self::catalogPosition($b->badge);
        });

        return $badges;
    }

    /**
     * Unearned badges, nearest first.
     *
     * By what is LEFT rather than by threshold, so the ordering tracks the member rather than the catalog:
     * somebody three responses from {@see BadgeKey::Collector} sees it above {@see BadgeKey::FirstReview},
     * which they have not started, even though the second has the smaller number on it.
     *
     * ⚠️ **THE TIE-BREAK IS THE THRESHOLD, ASCENDING, AND THE CASE IT EXISTS FOR IS A BRAND-NEW MEMBER —
     * i.e. every member, on the day the surface matters most.** With nothing done, each remainder equals
     * its own threshold, so the five threshold-1 badges all tie at 1 and the rest are already separated.
     * The threshold comparison is therefore a no-op in exactly that case and declaration order decides it,
     * which is what makes the shelf open on the five one-act badges rather than on the 250-response one.
     * It does real work later: two badges the same distance away put the cheaper one first.
     *
     * @param  list<BadgeStanding>  $badges  in catalog order
     * @return list<BadgeStanding>
     */
    private static function nearestFirst(array $badges): array
    {
        usort($badges, static function (BadgeStanding $a, BadgeStanding $b): int {
            return $a->remaining() <=> $b->remaining()
                ?: ($a->threshold <=> $b->threshold
                    ?: self::catalogPosition($a->badge) <=> self::catalogPosition($b->badge));
        });

        return $badges;
    }

    /**
     * Where a badge sits in {@see BadgeKey::cases()} — the stable last resort for both comparators.
     *
     * `array_search` over the case list rather than a stored index, because the enum is the catalog and a
     * second hand-maintained ordering beside it is the drift this file's own docblock argues against.
     * Ten cases, two sorts, once per request.
     */
    private static function catalogPosition(BadgeKey $badge): int
    {
        return (int) array_search($badge, BadgeKey::cases(), true);
    }
}
