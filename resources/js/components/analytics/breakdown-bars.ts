import type { BarDatum } from '@meridian/design-system';
import type { Breakdown } from './types';

/**
 * A breakdown → bar-chart mapping (Increment H24b2, generalised from H24b1's `Dashboard.vue`).
 *
 * Three shapes here each state something false if rendered carelessly, which is why one function owns all
 * three rather than each page rediscovering them:
 *
 *   · `other === null` means NOTHING overflowed the top-N. It does not mean an empty Other bucket, and a
 *     zero-valued "Other" bar would invent a category that does not exist.
 *   · `unassigned` is ALWAYS present, even at 0, and is never inside `rows`. At zero it earns no bar; above
 *     zero it is an ORDINARY bar, because it is a real set of forms and not a remainder.
 *   · The Other bucket is NEUTRAL (`--mds-chart-other`), never a recycled hue, so it cannot read as a peer
 *     category (ADR-0011 §D11). The full set stays in the paired data table and in the export — nothing is
 *     hidden, only un-plotted.
 *
 * ⚠️ THE TWO AGGREGATE BUCKETS ARE DELIBERATELY UNLINKED (J2d), and it falls out of the shapes above rather
 * than needing a special case: `unassigned` and `other` are not entities, so neither is built from a row and
 * neither carries a `url`. `BarChart.test.ts` already pins that an aggregate bucket stays inert.
 */
export function breakdownBars(breakdown: Breakdown): BarDatum[] {
    const bars: BarDatum[] = breakdown.rows.map((row) => ({
        // A null key is unreachable today (the aggregator diverts nulls into `unassigned`), but a bar
        // still needs a stable v-for key, and 'unassigned-row' cannot collide with the bucket below.
        key: row.key ?? 'unassigned-row',
        label: row.label,
        value: row.count,
        // ⚠️ `?? undefined`, NEVER `?? ''` (J2d). `MdsBarChart` treats an EMPTY href as "interactive", so a
        // falsy-to-empty-string mapping strips `role="img"`, its accessible name AND the sr-only table while
        // rendering zero links — the exact defect J2a's review caught in the chart itself, arriving from the
        // caller's side. `BarChart.test.ts` pins the component half; `breakdown-bars.test.ts` pins this one.
        href: row.url ?? undefined,
    }));

    if (breakdown.unassigned > 0) {
        bars.push({
            key: 'unassigned',
            // Axis-specific copy from the server: "Unassigned" on a locale chart would be wrong — those
            // rows are responses whose language was never recorded.
            label: breakdown.unassigned_label,
            value: breakdown.unassigned,
        });
    }

    if (breakdown.other !== null) {
        bars.push({
            key: 'other',
            label: `Other (${breakdown.other.categories} ${categoryNoun(breakdown, breakdown.other.categories)})`,
            value: breakdown.other.count,
            neutral: true,
        });
    }

    return bars;
}

/** The noun the overflow bucket counts, so "Other (2 forms)" does not read "Other (2 categories)". */
function categoryNoun(breakdown: Breakdown, count: number): string {
    const singular = {
        form: 'form',
        source: 'channel',
        status: 'status',
        scope_node: 'area',
        locale: 'language',
    }[breakdown.axis];

    if (count === 1) return singular;

    return singular === 'status' ? 'statuses' : `${singular}s`;
}

/**
 * Every row the chart is showing OR folding away, for the paired data table.
 *
 * §D11's "nothing is hidden, only un-plotted" is only true if the table holds the Other bucket's total and
 * a zero Unassigned that the plot legitimately drops. The chart primitives tabulate what they are GIVEN,
 * so the page must give the table more than it gives the plot.
 */
export function breakdownTableRows(breakdown: Breakdown): { key: string; label: string; count: number }[] {
    const rows = breakdown.rows.map((row, index) => ({
        key: row.key ?? `row-${index}`,
        label: row.label,
        count: row.count,
    }));

    if (breakdown.has_unassigned_bucket) {
        rows.push({ key: 'unassigned', label: breakdown.unassigned_label, count: breakdown.unassigned });
    }

    if (breakdown.other !== null) {
        rows.push({
            key: 'other',
            label: `Other (${breakdown.other.categories} ${categoryNoun(breakdown, breakdown.other.categories)})`,
            count: breakdown.other.count,
        });
    }

    return rows;
}
