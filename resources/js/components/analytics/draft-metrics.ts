import type { DraftMetrics } from './types';

/**
 * ADR-0011 §D5's two draft tiles, as one shared derivation (Increment H24b2).
 *
 * ── Why this is extracted rather than re-derived on the second page ─────────────────────────────────────
 * `/dashboard` and `/analytics` render the same tile pair from the same prop shape. The defect this logic
 * exists to prevent was found by LOOKING at the rendered dashboard, not by a gate, and it was subtle: with
 * six saved drafts and none yet submitted, the conversion tile correctly read "0% — of 6 saved drafts"
 * while the median tile, sharing one note, read "No drafts were explicitly saved in this period." Two tiles
 * side by side, disagreeing about whether any drafts exist.
 *
 * A second implementation on a second page is the one that regresses, and it would regress silently —
 * so the fix travels with the module, and its tests travel with it too.
 *
 * ── The two tiles are unavailable for DIFFERENT reasons and must say so separately ──────────────────────
 * The states are not the same shape. `conversion_rate` is null only when the denominator is zero;
 * `median_seconds` is ADDITIONALLY null when the denominator is positive but nothing in it has converted —
 * `percentile_cont` over an empty set returns NULL. That third state needs its own sentence, which is
 * §D5's "suppression has three states, never two" landing on the UI side of the same line.
 */

export const RETENTION_NOTE =
    'This period reaches past the draft retention window, and expired drafts are deleted — a rate computed over what survived would rise every night.';

export const NO_DRAFTS_NOTE = 'No drafts were explicitly saved in this period.';

export const NONE_CONVERTED_NOTE =
    'None of the drafts saved in this period have been submitted yet, so there is no first-save-to-submit time to measure.';

export interface TileState {
    /** Pre-formatted, or null when unavailable. MdsStatTile renders an em dash for null. */
    value: string | null;
    unavailable: boolean;
    /** The reason, shown only when unavailable. */
    note?: string;
    /** The denominator, shown only when a real number is on the tile. */
    caption?: string;
}

/** "of 6 saved drafts" — §D5 requires the denominator on the FACE of the tile, never in a tooltip. */
export function draftDenominator(drafts: DraftMetrics): string {
    const n = drafts.denominator;

    return `of ${n.toLocaleString()} saved ${n === 1 ? 'draft' : 'drafts'}`;
}

export function conversionTile(drafts: DraftMetrics): TileState {
    const unavailable = drafts.suppressed || drafts.conversion_rate === null;

    if (unavailable) {
        return { value: null, unavailable: true, note: drafts.suppressed ? RETENTION_NOTE : NO_DRAFTS_NOTE };
    }

    return { value: `${drafts.conversion_rate}%`, unavailable: false, caption: draftDenominator(drafts) };
}

export function medianTile(drafts: DraftMetrics): TileState {
    const unavailable = drafts.suppressed || drafts.median_seconds === null;

    if (unavailable) {
        // THREE arms, not two. Collapsing the last into NO_DRAFTS_NOTE is the exact defect above.
        const note = drafts.suppressed
            ? RETENTION_NOTE
            : drafts.denominator === 0
              ? NO_DRAFTS_NOTE
              : NONE_CONVERTED_NOTE;

        return { value: null, unavailable: true, note };
    }

    return {
        value: formatDuration(drafts.median_seconds as number),
        unavailable: false,
        caption: `${draftDenominator(drafts)}, first save to submit`,
    };
}

export function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);

        return `${hours}h ${Math.round((seconds - hours * 3600) / 60)}m`;
    }

    const days = Math.floor(seconds / 86400);

    return `${days}d ${Math.round((seconds - days * 86400) / 3600)}h`;
}
