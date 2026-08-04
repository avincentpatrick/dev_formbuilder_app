import type { Granularity } from './types';

/**
 * Bucket and range formatting for the analytics surfaces (Increment H24b2, generalised from H24b1's
 * `Dashboard.vue`).
 *
 * ── A BUCKET IS A CALENDAR DATE, NOT AN INSTANT, AND THAT IS THE WHOLE TRAP ─────────────────────────────
 * The server cuts buckets with `date_trunc(…, <zone>)` and hands back `YYYY-MM-DD` strings that are ALREADY
 * expressed in the query's timezone. Rendering one is therefore a pure string-to-label job: the zone has
 * been applied, and applying it again shifts the label.
 *
 * So every formatter here builds the instant at UTC midnight and formats it at **UTC**. That is not
 * "ignoring the timezone" — it is the only way to render the bucket's own day:
 *
 *   · Format at the VIEWER's zone: `new Date('2026-07-05T00:00:00Z')` renders "Jul 4" for anyone west of
 *     Greenwich. This is the classic form of the bug, and it is invisible to whoever writes the code.
 *   · Format at the QUERY's zone: identical failure whenever that zone has a negative offset — a report
 *     bucketed in America/New_York would label every one of its buckets a day early. H24b1 passed the
 *     query zone here and got away with it only because the dashboard's zone is hard-coded to UTC; the
 *     generalisation to a user-chosen zone is what exposed it, and `bucket-label.test.ts` is what caught it.
 *
 * The zone still matters to the READER, which is why {@see rangeLabel} prints it. It just must not be
 * applied twice.
 */

/** `YYYY-MM-DD` → the instant that calendar date began at UTC, so formatting at UTC returns it unchanged. */
function instant(isoDate: string): Date {
    return new Date(`${isoDate}T00:00:00Z`);
}

/**
 * A formatter for one bucket label at a given granularity.
 *
 * The week arm says "Week of …" rather than a bare date because the bucket IS the ISO Monday
 * (`AnalyticsGranularity::floor()` pins it) — a bare "Jul 6" on a weekly chart reads as one day's figure.
 */
export function bucketFormatter(granularity: Granularity): (bucket: string) => string {
    if (granularity === 'month') {
        const format = new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' });

        return (bucket: string): string => format.format(instant(bucket));
    }

    const format = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });

    if (granularity === 'week') {
        return (bucket: string): string => `Week of ${format.format(instant(bucket))}`;
    }

    return (bucket: string): string => format.format(instant(bucket));
}

/** One `YYYY-MM-DD` rendered with its year, for range banners and tile captions. */
export function dateLabel(isoDate: string): string {
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(instant(isoDate));
}

/**
 * "Jul 5, 2026 – Aug 3, 2026 (Asia/Manila)".
 *
 * The zone is NAMED rather than applied: two people in different places must be able to agree that they
 * are looking at the same thirty days, and a report whose days were cut in Manila says so.
 */
export function rangeLabel(from: string, to: string, timezone: string): string {
    return `${dateLabel(from)} – ${dateLabel(to)} (${timezone})`;
}

/** The x-axis's own name, so a weekly chart does not label its categories "Day". */
export function categoryLabel(granularity: Granularity): string {
    if (granularity === 'week') return 'Week';
    if (granularity === 'month') return 'Month';

    return 'Day';
}
