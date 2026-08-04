import { afterEach, describe, expect, it } from 'vitest';
import { bucketFormatter, categoryLabel, dateLabel, rangeLabel } from './bucket-label';

/**
 * The off-by-one this module exists to hold in one place — and the bug these tests actually FOUND.
 *
 * A bucket is a `YYYY-MM-DD` calendar date ALREADY expressed in the query's timezone: the server cut it
 * with `date_trunc(…, <zone>)`. Rendering it is therefore a string-to-label job, and applying a zone again
 * shifts it. Two ways to get that wrong, and the first version of this module had the second:
 *
 *   · Format at the VIEWER's zone — `new Date('2026-07-05T00:00:00Z')` renders "Jul 4" for anyone west of
 *     Greenwich. The classic form, invisible to whoever writes the code.
 *   · Format at the QUERY's zone — the same failure whenever THAT zone is negative-offset. H24b1 passed the
 *     query zone and got away with it because the dashboard's is hard-coded to UTC; generalising to a
 *     user-chosen zone is what exposed it.
 *
 * `withViewerZone()` below is what makes these real regression guards rather than tautologies: under a UTC
 * CI runner a formatter that dropped `timeZone: 'UTC'` would still return the right answer, so the test has
 * to put the runner somewhere else.
 */

const REAL_TZ = process.env.TZ;

afterEach(() => {
    process.env.TZ = REAL_TZ;
});

/** Run `fn` as if the reader's machine were in `zone`. Node re-reads `process.env.TZ` for each new Intl. */
function withViewerZone<T>(zone: string, fn: () => T): T {
    process.env.TZ = zone;

    return fn();
}

describe('bucketFormatter', () => {
    it('returns the bucket’s OWN day to a reader west of Greenwich', () => {
        // The instant is UTC midnight on the 5th, which is 20:00 on the 4th in New York. Without the
        // explicit UTC formatting this reads "Jul 4" — a whole column of the chart mislabelled.
        expect(withViewerZone('America/New_York', () => bucketFormatter('day')('2026-07-05'))).toBe('Jul 5');
    });

    it('returns the bucket’s OWN day to a reader east of Greenwich', () => {
        expect(withViewerZone('Asia/Manila', () => bucketFormatter('day')('2026-07-05'))).toBe('Jul 5');
    });

    it('names a week bucket as a week, because the bucket IS its Monday', () => {
        // AnalyticsGranularity::floor() pins ISO Monday. A bare "Jul 6" on a weekly chart reads as one
        // day's figure rather than seven.
        expect(bucketFormatter('week')('2026-07-06')).toBe('Week of Jul 6');
    });

    it('drops the day from a month bucket and keeps the year', () => {
        expect(bucketFormatter('month')('2026-07-01')).toBe('Jul 2026');
    });

    it('does not drift across a whole series, in either hemisphere of the meridian', () => {
        const labels = (zone: string): string[] =>
            withViewerZone(zone, () => {
                const format = bucketFormatter('day');

                return Array.from({ length: 5 }, (_, i) =>
                    format(`2026-07-${String(1 + i).padStart(2, '0')}`),
                );
            });

        const expected = ['Jul 1', 'Jul 2', 'Jul 3', 'Jul 4', 'Jul 5'];
        expect(labels('America/Los_Angeles')).toEqual(expected);
        expect(labels('Pacific/Auckland')).toEqual(expected);
    });
});

describe('range labelling', () => {
    it('NAMES the query zone rather than applying it', () => {
        // Two people in different places must be able to agree they are looking at the same thirty days,
        // which needs the zone printed and the dates left alone.
        expect(
            withViewerZone('America/New_York', () => rangeLabel('2026-07-05', '2026-08-03', 'Asia/Manila')),
        ).toBe('Jul 5, 2026 – Aug 3, 2026 (Asia/Manila)');
    });

    it('carries the year, because a report can span one — and does not roll it back', () => {
        expect(withViewerZone('America/New_York', () => dateLabel('2025-12-31'))).toBe('Dec 31, 2025');
    });
});

describe('categoryLabel', () => {
    it('names the x-axis after the granularity, so a weekly chart is not labelled "Day"', () => {
        expect(categoryLabel('day')).toBe('Day');
        expect(categoryLabel('week')).toBe('Week');
        expect(categoryLabel('month')).toBe('Month');
    });
});
