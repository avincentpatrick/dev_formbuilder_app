import { describe, expect, it } from 'vitest';
import { conversionTile, formatDuration, medianTile } from './draft-metrics';
import type { DraftMetrics } from './types';

/**
 * ADR-0011 §D5's three states, tested where they now LIVE rather than on either page.
 *
 * These assertions moved down from `dashboard.test.ts` when H24b2 extracted the module, and moving them is
 * the point: /dashboard and /analytics render the identical tile pair from the identical prop shape, so the
 * contract belongs to the thing they share. A second copy of this logic on the second page would have been
 * the one that regressed — and it would have regressed the way the original did, silently, in front of a
 * user, with every gate green.
 */

function drafts(overrides: Partial<DraftMetrics> = {}): DraftMetrics {
    return {
        suppressed: false,
        reason: null,
        denominator: 6,
        converted: 3,
        conversion_rate: 50,
        median_seconds: 372,
        ...overrides,
    };
}

describe('the three draft states', () => {
    it('shows both rates with their denominator when the data supports them', () => {
        expect(conversionTile(drafts())).toEqual({
            value: '50%',
            unavailable: false,
            caption: 'of 6 saved drafts',
        });
        expect(medianTile(drafts())).toEqual({
            value: '6m',
            unavailable: false,
            caption: 'of 6 saved drafts, first save to submit',
        });
    });

    it('suppresses BOTH tiles past the retention window, and never as a zero', () => {
        const past = drafts({
            suppressed: true,
            reason: 'beyond_draft_retention',
            denominator: 0,
            converted: null,
            conversion_rate: null,
            median_seconds: null,
        });

        for (const tile of [conversionTile(past), medianTile(past)]) {
            expect(tile.value).toBeNull();
            expect(tile.unavailable).toBe(true);
            expect(tile.note).toContain('draft retention window');
            // A suppressed rate rendered as "0%" is the same defect suppression exists to prevent.
            expect(tile.note).not.toContain('0%');
        }
    });

    it('says "no drafts saved" on both tiles when the denominator is genuinely zero', () => {
        const none = drafts({
            reason: 'no_saved_drafts',
            denominator: 0,
            converted: null,
            conversion_rate: null,
            median_seconds: null,
        });

        expect(conversionTile(none).note).toContain('No drafts were explicitly saved');
        expect(medianTile(none).note).toContain('No drafts were explicitly saved');
    });

    it('does not claim "no drafts were saved" when six were saved and none converted', () => {
        // THE defect. `percentile_cont` over an empty set returns NULL, so the median is unavailable while
        // the conversion rate is a real 0% over a real denominator of 6. One shared sentence made the two
        // tiles contradict each other on the same screen — found by looking, not by a gate.
        const noneConverted = drafts({ converted: 0, conversion_rate: 0, median_seconds: null });

        const conversion = conversionTile(noneConverted);
        expect(conversion.value).toBe('0%');
        expect(conversion.unavailable).toBe(false);
        expect(conversion.caption).toBe('of 6 saved drafts');

        const median = medianTile(noneConverted);
        expect(median.value).toBeNull();
        expect(median.unavailable).toBe(true);
        expect(median.note).toContain('have been submitted yet');
        expect(median.note).not.toContain('No drafts were explicitly saved');
    });

    it('keeps a zero conversion rate rather than treating it as missing', () => {
        // `?? ` not `||`: 0% over a real denominator is a number, and rendering it as an em dash would say
        // "we could not compute this" about a figure that is both computable and important.
        expect(conversionTile(drafts({ conversion_rate: 0 })).value).toBe('0%');
    });

    it('singularises the denominator at one draft', () => {
        expect(conversionTile(drafts({ denominator: 1 })).caption).toBe('of 1 saved draft');
    });
});

describe('formatDuration', () => {
    it('reads in the largest unit that keeps the number small', () => {
        expect(formatDuration(45)).toBe('45s');
        expect(formatDuration(372)).toBe('6m');
        expect(formatDuration(900)).toBe('15m');
        expect(formatDuration(5400)).toBe('1h 30m');
        expect(formatDuration(180_000)).toBe('2d 2h');
    });
});
