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

export interface BandRect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/**
 * Column rectangles for `MdsColumnChart`, on the 0-100 square its stretched canvas uses.
 *
 * `values[s][b]` is series `s` in band `b`; the result has the same shape, with `null` wherever no bar
 * is drawn. The x range is cut into `bandCount` equal bands and each band is inset by `padding` (a
 * fraction of the band) on both sides.
 *
 * - `grouped`: the padded band is split evenly among the series, and each bar runs from the zero line to
 *   its value — downward for a negative one. A missing value leaves its slot empty rather than closing
 *   the gap, so a series keeps the same position in every band.
 * - `stacked`: one full-width bar per band, segments piled upward from zero in series order. A negative
 *   or missing value contributes nothing and yields `null`.
 *
 * A zero is a real value and yields a zero-height rect, which SVG does not paint. A degenerate or
 * non-finite domain collapses every bar onto the baseline instead of emitting `NaN`.
 */
export function bandRects(
    values: (number | null | undefined)[][],
    bandCount: number,
    domainMin: number,
    domainMax: number,
    mode: 'grouped' | 'stacked' = 'grouped',
    padding = 0.15,
): (BandRect | null)[][] {
    if (!Number.isFinite(bandCount) || bandCount <= 0) return values.map(() => []);

    const finiteDomain = Number.isFinite(domainMin) && Number.isFinite(domainMax);
    const y = (value: number): number =>
        finiteDomain ? scaleLinear(value, domainMin, domainMax, 100, 0) : 100;

    const inset = Number.isFinite(padding) ? Math.min(Math.max(padding, 0), 0.45) : 0.15;
    const band = 100 / bandCount;
    const inner = band * (1 - 2 * inset);
    const stacked = mode === 'stacked';
    const width = stacked ? inner : inner / Math.max(1, values.length);
    const stackTop: number[] = Array.from({ length: bandCount }, () => 0);

    return values.map((row, s) =>
        Array.from({ length: bandCount }, (_, b): BandRect | null => {
            const value = row[b];
            if (typeof value !== 'number' || !Number.isFinite(value)) return null;
            if (stacked && value < 0) return null;

            const from = stacked ? stackTop[b] : 0;
            const to = from + value;
            if (stacked) stackTop[b] = to;

            // Both edges are rounded BEFORE the height is taken, so stacked segments tile exactly
            // instead of each rounding its own height and leaving a hairline.
            const top = coord(Math.min(y(from), y(to)));
            const bottom = coord(Math.max(y(from), y(to)));

            return {
                x: coord(b * band + band * inset + (stacked ? 0 : s * width)),
                y: top,
                width: coord(width),
                height: coord(bottom - top),
            };
        }),
    );
}

/**
 * An SVG `d` for an annular sector — one donut slice. Angles are radians, 0 at 12 o'clock, clockwise.
 *
 * ⚠️ A FULL CIRCLE IS TWO HALF ARCS, NOT ONE. An arc whose start and end points coincide is omitted by
 * the SVG renderer, so a single 360° slice — the ordinary shape of a breakdown with one non-zero
 * category — would draw nothing, throw nothing, and fail no gate. The same guard covers a slice so close
 * to full that its two ends ROUND to the same coordinate.
 *
 * `innerR <= 0` gives a plain pie sector. An empty or non-finite sweep yields an empty path.
 */
export function arcPath(
    cx: number,
    cy: number,
    outerR: number,
    innerR: number,
    startAngle: number,
    endAngle: number,
): string {
    if (![cx, cy, outerR, innerR, startAngle, endAngle].every((n) => Number.isFinite(n))) return '';

    const sweep = endAngle - startAngle;
    if (outerR <= 0 || sweep <= 0) return '';

    const hole = Math.min(Math.max(innerR, 0), outerR);
    const at = (r: number, angle: number): string =>
        `${coord(cx + r * Math.sin(angle))} ${coord(cy - r * Math.cos(angle))}`;
    const arc = (r: number, large: 0 | 1, clockwise: 0 | 1, angle: number): string =>
        `A${coord(r)} ${coord(r)} 0 ${large} ${clockwise} ${at(r, angle)}`;

    const full = sweep >= 2 * Math.PI || (sweep > Math.PI && at(outerR, startAngle) === at(outerR, endAngle));
    if (full) {
        const half = startAngle + Math.PI;
        const ring = (r: number, clockwise: 0 | 1): string =>
            `M${at(r, startAngle)} ${arc(r, 1, clockwise, half)} ${arc(r, 1, clockwise, startAngle)} Z`;

        // The hole runs the other way round, so it is a hole under either fill rule.
        return hole > 0 ? `${ring(outerR, 1)} ${ring(hole, 0)}` : ring(outerR, 1);
    }

    const large = sweep > Math.PI ? 1 : 0;
    const outer = `M${at(outerR, startAngle)} ${arc(outerR, large, 1, endAngle)}`;
    if (hole <= 0) return `${outer} L${coord(cx)} ${coord(cy)} Z`;

    return `${outer} L${at(hole, endAngle)} ${arc(hole, large, 0, startAngle)} Z`;
}
