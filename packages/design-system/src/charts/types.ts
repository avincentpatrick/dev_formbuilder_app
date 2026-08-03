/**
 * Shared chart data shapes (DSR §3.11).
 *
 * Labels arrive DISPLAY-READY. The primitives never format a date, a number or a locale — that is the
 * same division `StatTile` already documents ("the parent computes and formats the value"), and here it
 * also keeps `Intl` out of the design system, which the guest runtime shares.
 */

export interface ChartPoint {
    label: string;
    value: number;
}

export interface ChartSeries {
    key: string;
    label: string;
    points: ChartPoint[];
}

export interface BarDatum {
    key: string;
    label: string;
    value: number;
    /**
     * Paints the bar with `--mds-chart-other` instead of a series hue. ADR-0011 §D11: past five
     * categories the caller ships the top four plus one aggregated *Other (N)* bucket, painted a
     * NEUTRAL and never a recycled hue, so "Other" never reads as a peer category.
     */
    neutral?: boolean;
}

export interface ChartLegendItem {
    key: string;
    label: string;
    /** 1-5. Ignored when `neutral` is set. */
    series?: number;
    neutral?: boolean;
}

/** The categorical scale is capped at five — derived, not chosen (ADR-0011 §D11). */
export const MAX_CHART_SERIES = 5;
