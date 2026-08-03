/**
 * Chart arithmetic — the whole of it (DSR §3.11, ADR-0011 §D10).
 *
 * ~100 lines of in-repo maths instead of a scale library. That is a deliberate decision and not a
 * matter of taste: CI's `npm audit --omit=dev --audit-level=high` gates PRODUCTION dependencies, so a
 * "tiny" scale package is a permanent supply-chain surface bought for the functions below. §D10 names
 * the temptation explicitly — *do not add a scale library "because it is small"*.
 *
 * Everything here is pure and total: no clock, no randomness, no DOM. That is what lets the chart
 * components be asserted by their emitted `d` strings under happy-dom, which lays nothing out and
 * resolves no `var()` — the only geometry a unit test can see is the geometry we computed ourselves.
 */

/** SVG coordinates are rounded so `d` strings stay short and byte-stable across runs. */
export function coord(value: number): number {
    return Math.round(value * 100) / 100;
}

/** The classic "nice number" step: 1, 2, 5 or 10 × a power of ten. */
function niceNum(range: number, round: boolean): number {
    const exponent = Math.floor(Math.log10(range));
    const fraction = range / 10 ** exponent;

    let nice: number;
    if (round) {
        if (fraction < 1.5) nice = 1;
        else if (fraction < 3) nice = 2;
        else if (fraction < 7) nice = 5;
        else nice = 10;
    } else {
        if (fraction <= 1) nice = 1;
        else if (fraction <= 2) nice = 2;
        else if (fraction <= 5) nice = 5;
        else nice = 10;
    }

    return nice * 10 ** exponent;
}

/**
 * Round tick values spanning [min, max], inclusive of both ends after widening.
 *
 * A degenerate domain returns `[min, min + 1]` rather than a single tick, because every caller then
 * divides by (max − min): an all-zero series — the ordinary state of a quiet 30-day window — would
 * otherwise produce NaN coordinates and an empty chart with no error anywhere.
 */
export function niceTicks(min: number, max: number, count = 5): number[] {
    if (!Number.isFinite(min) || !Number.isFinite(max) || count < 2) return [0, 1];
    if (max <= min) return [min, min + 1];

    const step = niceNum(niceNum(max - min, false) / (count - 1), true);
    const start = Math.floor(min / step) * step;
    const end = Math.ceil(max / step) * step;

    // Steps below 1 (a fractional axis) would accumulate float error; derive the precision from the
    // step itself rather than rounding to a fixed number of places.
    const places = Math.max(0, -Math.floor(Math.log10(step)));
    const ticks: number[] = [];
    for (let i = 0; start + i * step <= end + step / 2; i++) {
        ticks.push(Number((start + i * step).toFixed(places)));
    }

    return ticks;
}

/** Linear interpolation from a value domain onto a coordinate range. Degenerate domain → `rangeMin`. */
export function scaleLinear(
    value: number,
    domainMin: number,
    domainMax: number,
    rangeMin: number,
    rangeMax: number,
): number {
    if (domainMax === domainMin) return rangeMin;

    return rangeMin + ((value - domainMin) / (domainMax - domainMin)) * (rangeMax - rangeMin);
}

/**
 * Evenly spaced x positions for `count` points across [left, right].
 *
 * A single point sits at the midpoint rather than at `left`, so a one-day range draws a mark in the
 * middle of the plot instead of welded to the y-axis.
 */
export function bandPositions(count: number, left: number, right: number): number[] {
    if (count <= 0) return [];
    if (count === 1) return [coord((left + right) / 2)];

    const step = (right - left) / (count - 1);

    return Array.from({ length: count }, (_, i) => coord(left + i * step));
}

export interface Point {
    x: number;
    y: number;
}

/** An SVG `d` for a polyline through the points. Empty input yields an empty path, never `"M"`. */
export function linePath(points: Point[]): string {
    if (points.length === 0) return '';

    return points
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${coord(p.x)} ${coord(p.y)}`)
        .join(' ');
}

/** The same line closed down to a baseline, for the filled `area` variant. */
export function areaPath(points: Point[], baselineY: number): string {
    if (points.length === 0) return '';

    const first = points[0];
    const last = points[points.length - 1];

    return `${linePath(points)} L${coord(last.x)} ${coord(baselineY)} L${coord(first.x)} ${coord(baselineY)} Z`;
}

/**
 * Indices of at most `max` items, spread EVENLY across the series, always keeping the first and last.
 *
 * This is axis-label THINNING, not collision avoidance — §D10 lists automatic axis-label collision
 * avoidance among the things deliberately not built, because doing it properly needs text measurement
 * that happy-dom cannot provide and that would make the output depend on the rendered font. A stride
 * is deterministic, testable, and honest about what it is.
 *
 * Evenness is load-bearing rather than cosmetic: the x-axis labels are laid out by
 * `justify-content: space-between`, which distributes them uniformly. A fixed stride
 * (`ceil((n-1)/(max-1))`) leaves a SHORT final gap — 6,6,6,6,5 over 30 days — so every label but the
 * ends would sit slightly off its own data point. Interpolating the indices instead keeps the two
 * within half a bucket of each other, with no per-label positioning and therefore no `:style` binding.
 */
export function strideLabels(length: number, max: number): number[] {
    if (length <= 0) return [];
    if (max < 2 || length <= max) return Array.from({ length }, (_, i) => i);

    const seen = new Set<number>();
    for (let i = 0; i < max; i++) seen.add(Math.round((i * (length - 1)) / (max - 1)));

    return [...seen].sort((a, b) => a - b);
}
