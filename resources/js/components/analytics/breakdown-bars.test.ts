import { describe, expect, it } from 'vitest';
import { breakdownBars, breakdownTableRows } from './breakdown-bars';
import type { Breakdown } from './types';

/**
 * Three shapes that each state something FALSE if rendered carelessly, which is why one function owns all
 * three rather than each page rediscovering them:
 *   · `other === null` means nothing overflowed — not an empty Other bucket.
 *   · `unassigned` is always present, even at 0, and is never inside `rows`.
 *   · the Other bucket is neutral; Unassigned is an ordinary bar, because it is a real set, not a remainder.
 */

function breakdown(overrides: Partial<Breakdown> = {}): Breakdown {
    return {
        axis: 'form',
        rows: [
            { key: 'f1', label: 'Clinic Intake', count: 9 },
            { key: 'f2', label: 'Household Roster', count: 4 },
        ],
        other: null,
        unassigned: 0,
        unassigned_label: 'Unassigned',
        has_unassigned_bucket: false,
        ...overrides,
    };
}

describe('breakdownBars', () => {
    it('plots the labelled rows and nothing else when nothing overflowed', () => {
        expect(breakdownBars(breakdown()).map((b) => b.label)).toEqual([
            'Clinic Intake',
            'Household Roster',
        ]);
    });

    it('drops a zero Unassigned bucket, which is ALWAYS present in the prop', () => {
        expect(breakdownBars(breakdown({ has_unassigned_bucket: true })).map((b) => b.key)).toEqual([
            'f1',
            'f2',
        ]);
    });

    it('keeps a non-zero Unassigned as an ORDINARY bar, not a remainder', () => {
        const bars = breakdownBars(breakdown({ unassigned: 3, has_unassigned_bucket: true }));

        expect(bars.map((b) => b.label)).toEqual(['Clinic Intake', 'Household Roster', 'Unassigned']);
        // Unassigned is a real set of forms. Painting it neutral would say "miscellaneous" about a bucket
        // the user can act on.
        expect(bars.find((b) => b.key === 'unassigned')?.neutral).toBeUndefined();
    });

    it('adds the aggregated bucket as a NEUTRAL bar naming how many it holds', () => {
        const bars = breakdownBars(breakdown({ other: { count: 7, categories: 3 } }));

        expect(bars.at(-1)).toEqual({
            key: 'other',
            label: 'Other (3 forms)',
            value: 7,
            neutral: true,
        });
        expect(bars.filter((b) => b.neutral)).toHaveLength(1);
    });

    it('singularises the overflow bucket at one category', () => {
        const bars = breakdownBars(breakdown({ other: { count: 2, categories: 1 } }));

        expect(bars.at(-1)?.label).toBe('Other (1 form)');
    });

    it('names the overflow after the AXIS, so a channel chart does not say "forms"', () => {
        expect(breakdownBars(breakdown({ axis: 'source', other: { count: 2, categories: 2 } })).at(-1)?.label)
            .toBe('Other (2 channels)');
        expect(breakdownBars(breakdown({ axis: 'locale', other: { count: 2, categories: 4 } })).at(-1)?.label)
            .toBe('Other (4 languages)');
        // The one that a naive `+ 's'` gets wrong.
        expect(breakdownBars(breakdown({ axis: 'status', other: { count: 2, categories: 3 } })).at(-1)?.label)
            .toBe('Other (3 statuses)');
    });

    it('uses the axis-specific Unassigned copy the server supplies', () => {
        // "Unassigned" on a locale chart would be wrong: those are responses whose language was never
        // recorded, not responses somebody forgot to file.
        const bars = breakdownBars(
            breakdown({ axis: 'locale', unassigned: 5, unassigned_label: 'Not recorded', has_unassigned_bucket: true }),
        );

        expect(bars.at(-1)?.label).toBe('Not recorded');
    });
});

describe('breakdownTableRows', () => {
    it('tabulates a ZERO Unassigned that the plot legitimately drops', () => {
        // §D11's "nothing is hidden, only un-plotted" is only true if the table holds what the chart does
        // not. Deleting this loses coverage no gate replaces.
        const rows = breakdownTableRows(breakdown({ has_unassigned_bucket: true }));

        expect(rows.map((r) => r.label)).toEqual(['Clinic Intake', 'Household Roster', 'Unassigned']);
        expect(rows.at(-1)?.count).toBe(0);
    });

    it('tabulates the Other bucket’s total alongside the plotted rows', () => {
        const rows = breakdownTableRows(breakdown({ other: { count: 7, categories: 3 } }));

        expect(rows.at(-1)).toEqual({ key: 'other', label: 'Other (3 forms)', count: 7 });
    });

    it('omits an Unassigned row on an axis that cannot have one', () => {
        // `form_id` is NOT NULL, so an Unassigned row there would invent a bucket.
        expect(breakdownTableRows(breakdown()).map((r) => r.key)).toEqual(['f1', 'f2']);
    });
});
