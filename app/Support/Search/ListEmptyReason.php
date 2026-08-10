<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * Why a row-list rendered zero rows (Increment J1e).
 *
 * ── THE RULE THIS ENCODES, WHICH I2 STATED AND J1e MAKES SHARED ──────────────────────────────────────────
 * Server-computed, one prop, one meaning. Inferring "filtered to zero" on the client from
 * `data.length === 0 && someFilterSet` breaks the moment the server defaults or clamps anything — and both
 * happen here: the submissions inbox applies `countable()` as a DISPLAY DEFAULT when no status is chosen,
 * and {@see SearchTerms::parse()} silently clamps a long paste. A client cannot see either, so a client-side
 * inference tells an owner holding 5,000 rows that nothing was ever recorded.
 *
 * ── WHY A CLASS FOR A THREE-ARM `match` ──────────────────────────────────────────────────────────────────
 * Six lists now emit this prop and six page components branch on the STRINGS. `no_matches` misspelled as
 * `no_match` on one presenter is a silently wrong empty state — the page falls through to its "you have
 * nothing yet" arm, which is the exact lie J1e exists to remove on `/forms`. One definition site is what
 * makes the six agree; the constants are what make a typo a fatal rather than a wrong screen.
 *
 * `AuditLogPresenter` and `FeedbackPresenter` had this inline before J1e and now call through here. Their
 * tests pass UNEDITED, which is the proof the extraction moved nothing.
 */
final class ListEmptyReason
{
    /** The list has rows in principle; the filters in force matched none of them. */
    public const string NO_MATCHES = 'no_matches';

    /** The list is genuinely empty — nothing has ever been created here. */
    public const string NO_ROWS = 'no_rows';

    /**
     * @return self::NO_MATCHES|self::NO_ROWS|null
     */
    public static function for(bool $hasRows, bool $hasFilters): ?string
    {
        return match (true) {
            $hasRows => null,
            $hasFilters => self::NO_MATCHES,
            default => self::NO_ROWS,
        };
    }
}
