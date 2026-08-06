import { afterEach, describe, expect, it } from 'vitest';
import { absoluteTime, relativeTime } from './relative-time';

/**
 * The timezone discipline here is the EXACT INVERSE of `bucket-label.ts`'s, and these tests exist to keep
 * it that way after someone reads that file and "fixes" this one.
 *
 * A bucket is a calendar date the query already cut in a chosen zone, so it must be formatted at UTC. A
 * `notifications.created_at` is an INSTANT, so the relative arithmetic must be zone-free and the ABSOLUTE
 * title must render in the viewer's own zone. Both halves are asserted below by moving the runner.
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

const NOW = new Date('2026-08-06T12:00:00Z');

function ago(ms: number): string {
    return new Date(NOW.getTime() - ms).toISOString();
}

describe('relativeTime', () => {
    it('says “now” under a minute rather than counting seconds a 60-second poll cannot know', () => {
        expect(relativeTime(ago(0), NOW)).toBe('now');
        expect(relativeTime(ago(59_000), NOW)).toBe('now');
    });

    it('clamps a future timestamp, so clock skew never renders “in 4 seconds”', () => {
        // The server stamps `created_at`; the browser supplies `now`. They disagree by seconds routinely.
        expect(relativeTime(new Date(NOW.getTime() + 4_000).toISOString(), NOW)).toBe('now');
    });

    it('floors rather than rounds, so 59m40s never prints “60 minutes ago”', () => {
        expect(relativeTime(ago(59 * 60_000 + 40_000), NOW)).toBe('59 minutes ago');
    });

    it('uses the locale idiom one unit back — “yesterday”, “last week”, “last month”', () => {
        // `numeric: 'auto'` is chosen for exactly this: "1 day ago" is not what anyone says, and the whole
        // point of a bell row's timestamp is that it reads at a glance.
        expect(relativeTime(ago(24 * 3_600_000), NOW)).toBe('yesterday');
        expect(relativeTime(ago(7 * 24 * 3_600_000), NOW)).toBe('last week');
        expect(relativeTime(ago(45 * 24 * 3_600_000), NOW)).toBe('last month');
        expect(relativeTime(ago(400 * 24 * 3_600_000), NOW)).toBe('last year');
    });

    it('steps up through hours, days, weeks and months', () => {
        expect(relativeTime(ago(2 * 3_600_000), NOW)).toBe('2 hours ago');
        expect(relativeTime(ago(3 * 24 * 3_600_000), NOW)).toBe('3 days ago');
        expect(relativeTime(ago(21 * 24 * 3_600_000), NOW)).toBe('3 weeks ago');
        expect(relativeTime(ago(120 * 24 * 3_600_000), NOW)).toBe('4 months ago');
    });

    it('produces an IDENTICAL label in Auckland and Los Angeles — the arithmetic is over instants', () => {
        // ← the guard this module exists for. A zone can only enter through a formatter that has no
        // business seeing one; the subtraction above cannot care where the reader is standing.
        const auckland = withViewerZone('Pacific/Auckland', () => relativeTime(ago(3 * 3_600_000), NOW));
        const losAngeles = withViewerZone('America/Los_Angeles', () => relativeTime(ago(3 * 3_600_000), NOW));

        expect(auckland).toBe(losAngeles);
        expect(auckland).toBe('3 hours ago');
    });

    it('returns an empty string for an unparseable timestamp instead of “Invalid Date”', () => {
        expect(relativeTime('not-a-date', NOW)).toBe('');
    });
});

describe('absoluteTime', () => {
    it('renders in the VIEWER’s zone — the opposite discipline from bucket-label.ts', () => {
        // Pinning this to UTC (the reflex after reading bucket-label.ts) would tell a Manila reader their
        // submission arrived eight hours before it did.
        const manila = withViewerZone('Asia/Manila', () => absoluteTime('2026-08-06T23:30:00Z'));
        const utc = withViewerZone('UTC', () => absoluteTime('2026-08-06T23:30:00Z'));

        expect(manila).not.toBe(utc);
        // 23:30Z is 07:30 the NEXT day in Manila — the date rolls, not just the clock.
        expect(manila).toContain('Aug 7');
        expect(utc).toContain('Aug 6');
    });

    it('returns an empty string for an unparseable timestamp', () => {
        expect(absoluteTime('')).toBe('');
    });
});
