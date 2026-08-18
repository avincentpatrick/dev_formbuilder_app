/**
 * Relative + absolute timestamps for the notification centre (Increment I4). The repo's second extracted,
 * tested formatting helper, after `components/analytics/bucket-label.ts` — every other surface hand-rolls a
 * private `formatDate()`, which is fine for a table cell and not fine for "2 hours ago", where the
 * arithmetic is the part that can be wrong.
 *
 * ⚠️ THE TIMEZONE DISCIPLINE HERE IS THE EXACT INVERSE OF `bucket-label.ts`'s, AND COPYING THAT FILE IS
 * THE BUG. A bucket label formats a CALENDAR DATE that the query already cut in a chosen zone, so it must
 * be rendered at UTC or it shifts a day for anyone west of Greenwich. A `notifications.created_at` is an
 * INSTANT (ISO 8601 with an offset). So:
 *   · the relative arithmetic below is pure instant subtraction — no zone can enter it, and the test
 *     proves the label is byte-identical in Auckland and Los Angeles;
 *   · the ABSOLUTE title deliberately passes NO `timeZone` option, so it renders in the READER's zone.
 *     Pinning it to UTC — the reflex after reading bucket-label.ts — would tell someone in Manila their
 *     submission arrived eight hours before it did.
 *
 * No dependency is added for this. `Intl.RelativeTimeFormat` is in every browser the product supports, and
 * the shell is loaded on every page, so a date library here would be permanent bundle weight and permanent
 * `npm audit` surface for two functions.
 */
const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * "2 hours ago" / "yesterday" / "now".
 *
 * @param now injectable so the tests are not racing the clock; production always passes nothing.
 */
export function relativeTime(iso: string, now: Date = new Date()): string {
    const then = new Date(iso);

    // Never render "Invalid Date" into a row. An unparseable timestamp is a server bug, and the honest
    // client behaviour is to say nothing rather than to print a stack-trace-shaped string at the user.
    if (Number.isNaN(then.getTime())) return '';

    // Constructed per call rather than memoized at module scope: Intl freezes its resolved locale and zone
    // at construction, and `relative-time.test.ts` swaps `process.env.TZ` between cases exactly as
    // `bucket-label.test.ts` does — a cached formatter would quietly make those guards tautologies.
    // Fifteen rows a minute is nothing.
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto', style: 'long' });

    // CLAMPED, not signed. Server and browser clocks routinely disagree by seconds, and without this a
    // three-second skew renders "in 3 seconds" on a row describing something that already happened.
    const elapsed = Math.max(0, now.getTime() - then.getTime());

    // Sub-minute is its own case, and it is not "0 seconds ago": the feed is polled every 60 seconds, so
    // second-level precision is a promise the data cannot keep. `numeric: 'auto'` renders this as "now".
    if (elapsed < MINUTE) return rtf.format(0, 'second');

    // FLOOR at every step, never round: rounding lets 59m40s fall into the minute bucket and print
    // "60 minutes ago", which is both wrong and visibly silly next to an "an hour ago" beneath it.
    const minutes = Math.floor(elapsed / MINUTE);
    if (minutes < 60) return rtf.format(-minutes, 'minute');

    const hours = Math.floor(elapsed / HOUR);
    if (hours < 24) return rtf.format(-hours, 'hour');

    const days = Math.floor(elapsed / DAY);
    if (days < 7) return rtf.format(-days, 'day');
    if (days < 30) return rtf.format(-Math.floor(days / 7), 'week');
    if (days < 365) return rtf.format(-Math.floor(days / 30), 'month');

    return rtf.format(-Math.floor(days / 365), 'year');
}

/**
 * The exact instant, for the row's `title` attribute — in the READER's zone, and with the same field set
 * every other surface in the app uses for a timestamp.
 */
export function absoluteTime(iso: string): string {
    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) return '';

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(at);
}
