import { describe, expect, it } from 'vitest';
import {
    arcPath,
    areaPath,
    bandPositions,
    bandRects,
    coord,
    linePath,
    niceTicks,
    scaleLinear,
    strideLabels,
} from './scale';

/**
 * The chart arithmetic is where a silent NaN would come from, so it is pinned directly rather than
 * only through the components that consume it.
 *
 * The load-bearing case is the DEGENERATE domain. `/dashboard` serves a 30-point, zero-filled series
 * (`AnalyticsMetricsService::series()`), and on a quiet tenant every one of those points is 0 — so
 * `max - min` is 0 on the ordinary path, not on an exotic one. Every consumer divides by that span;
 * without the guard the chart renders `d="M0 NaN L20 NaN …"`, which draws nothing, throws nothing,
 * and fails no gate.
 */

describe('niceTicks', () => {
    it('produces round steps covering the domain', () => {
        expect(niceTicks(0, 97)).toEqual([0, 20, 40, 60, 80, 100]);
        expect(niceTicks(0, 8)).toEqual([0, 2, 4, 6, 8]);
    });

    it('never returns a single tick for a flat domain — every caller divides by the span', () => {
        expect(niceTicks(0, 0)).toEqual([0, 1]);
        expect(niceTicks(4, 4)).toEqual([4, 5]);
        expect(niceTicks(0, -3)).toEqual([0, 1]);
    });

    it('survives a non-finite domain rather than emitting NaN ticks', () => {
        expect(niceTicks(0, Number.NaN)).toEqual([0, 1]);
        expect(niceTicks(0, Number.POSITIVE_INFINITY)).toEqual([0, 1]);
    });

    it('derives its precision from the step, so a fractional axis does not drift', () => {
        // Steps are 1/2/5 × a power of ten, so a unit domain divides by 0.2 — and naive float
        // accumulation would give 0.30000000000000004 / 0.6000000000000001 rather than these.
        expect(niceTicks(0, 1)).toEqual([0, 0.2, 0.4, 0.6, 0.8, 1]);
        for (const tick of niceTicks(0, 0.7)) {
            expect(String(tick).length).toBeLessThanOrEqual(4);
        }
    });

    it('always includes the domain ends after widening', () => {
        const ticks = niceTicks(0, 137);
        expect(ticks[0]).toBeLessThanOrEqual(0);
        expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(137);
    });
});

describe('scaleLinear', () => {
    it('maps a domain onto an inverted range (SVG y grows downward)', () => {
        expect(scaleLinear(0, 0, 100, 180, 0)).toBe(180);
        expect(scaleLinear(100, 0, 100, 180, 0)).toBe(0);
        expect(scaleLinear(50, 0, 100, 180, 0)).toBe(90);
    });

    it('returns the range floor for a degenerate domain instead of NaN', () => {
        expect(scaleLinear(0, 5, 5, 180, 0)).toBe(180);
    });
});

describe('bandPositions', () => {
    it('spreads points evenly across the plot', () => {
        expect(bandPositions(3, 0, 100)).toEqual([0, 50, 100]);
    });

    it('centres a single point rather than welding it to the y-axis', () => {
        expect(bandPositions(1, 20, 100)).toEqual([60]);
    });

    it('returns nothing for an empty series', () => {
        expect(bandPositions(0, 0, 100)).toEqual([]);
    });
});

describe('path builders', () => {
    it('emits a move followed by lines', () => {
        expect(
            linePath([
                { x: 0, y: 10 },
                { x: 5, y: 0 },
            ]),
        ).toBe('M0 10 L5 0');
    });

    it('emits an empty string for no points, never a bare "M"', () => {
        expect(linePath([])).toBe('');
        expect(areaPath([], 100)).toBe('');
    });

    it('closes the area down to the baseline', () => {
        expect(
            areaPath(
                [
                    { x: 0, y: 10 },
                    { x: 5, y: 0 },
                ],
                20,
            ),
        ).toBe('M0 10 L5 0 L5 20 L0 20 Z');
    });

    it('rounds coordinates so the path is byte-stable', () => {
        expect(coord(1 / 3)).toBe(0.33);
        expect(linePath([{ x: 1 / 3, y: 2 / 3 }])).toBe('M0.33 0.67');
    });
});

describe('strideLabels', () => {
    it('keeps every index when the series already fits', () => {
        expect(strideLabels(4, 6)).toEqual([0, 1, 2, 3]);
    });

    it('thins a 30-day series to at most the requested count, keeping both ends', () => {
        const indices = strideLabels(30, 6);
        expect(indices.length).toBeLessThanOrEqual(6);
        expect(indices[0]).toBe(0);
        expect(indices[indices.length - 1]).toBe(29);
    });

    it('never exceeds the cap for any length up to a year', () => {
        for (let length = 1; length <= 366; length++) {
            expect(strideLabels(length, 6).length, `length ${length}`).toBeLessThanOrEqual(6);
        }
    });

    it('emits strictly increasing, in-range indices', () => {
        for (const length of [7, 13, 30, 31, 90, 366]) {
            const indices = strideLabels(length, 6);
            for (let i = 1; i < indices.length; i++) expect(indices[i]).toBeGreaterThan(indices[i - 1]);
            expect(indices[indices.length - 1]).toBeLessThan(length);
        }
    });

    it('spreads the gaps evenly, because space-between assumes they are', () => {
        // A fixed stride of ceil(29/5) gives 0,6,12,18,24,29 — gaps 6,6,6,6,5, so the last label
        // drifts off its own data point. Interpolated indices keep every gap within one bucket.
        const indices = strideLabels(30, 6);
        const gaps = indices.slice(1).map((v, i) => v - indices[i]);
        expect(Math.max(...gaps) - Math.min(...gaps)).toBeLessThanOrEqual(1);
    });

    it('handles an empty series', () => {
        expect(strideLabels(0, 6)).toEqual([]);
    });
});

describe('bandRects', () => {
    // Two bands of 50 units; the default 15% inset leaves a padded band 35 wide, starting at 7.5 / 57.5.
    it('splits each padded band evenly among grouped series', () => {
        expect(
            bandRects(
                [
                    [10, 5],
                    [2, 8],
                ],
                2,
                0,
                10,
            ),
        ).toEqual([
            [
                { x: 7.5, y: 0, width: 17.5, height: 100 },
                { x: 57.5, y: 50, width: 17.5, height: 50 },
            ],
            [
                { x: 25, y: 80, width: 17.5, height: 20 },
                { x: 75, y: 20, width: 17.5, height: 80 },
            ],
        ]);
    });

    it('draws a negative grouped value from the zero line DOWNWARD', () => {
        // Domain [-10, 10] puts zero at y = 50; -5 reaches y = 75.
        expect(bandRects([[-5]], 1, -10, 10)).toEqual([[{ x: 15, y: 50, width: 70, height: 25 }]]);
    });

    it('leaves a missing grouped value as an empty slot, never a shifted neighbour', () => {
        const rects = bandRects([[null], [4]], 1, 0, 4);

        expect(rects[0]).toEqual([null]);
        // Still the SECOND slot: a series keeps its position in every band.
        expect(rects[1]).toEqual([{ x: 50, y: 0, width: 35, height: 100 }]);
    });

    it('stacks segments upward from zero in series order', () => {
        expect(
            bandRects(
                [
                    [10, 5],
                    [10, 15],
                ],
                2,
                0,
                20,
                'stacked',
            ),
        ).toEqual([
            [
                { x: 7.5, y: 50, width: 35, height: 50 },
                { x: 57.5, y: 75, width: 35, height: 25 },
            ],
            [
                { x: 7.5, y: 0, width: 35, height: 50 },
                { x: 57.5, y: 0, width: 35, height: 75 },
            ],
        ]);
    });

    it('lets a negative or missing value contribute nothing to a stack', () => {
        const rects = bandRects([[4], [-3], [null], [undefined], [6]], 1, 0, 10, 'stacked');

        expect(rects.slice(1, 4)).toEqual([[null], [null], [null]]);
        // The last segment sits directly on the first: 4 → 10, undisturbed by the -3 between them.
        expect(rects[0]).toEqual([{ x: 15, y: 60, width: 70, height: 40 }]);
        expect(rects[4]).toEqual([{ x: 15, y: 0, width: 70, height: 60 }]);
    });

    it('tiles stacked segments exactly, with no rounding hairline between them', () => {
        // Thirds of a [0, 3] domain: every boundary is a repeating decimal.
        const [[a], [b], [c]] = bandRects([[1], [1], [1]], 1, 0, 3, 'stacked');

        expect(coord((b?.y ?? 0) + (b?.height ?? 0))).toBe(a?.y);
        expect(coord((c?.y ?? 0) + (c?.height ?? 0))).toBe(b?.y);
        expect(c?.y).toBe(0);
    });

    it('honours a custom inset and clamps an absurd one', () => {
        expect(bandRects([[1]], 1, 0, 1, 'grouped', 0)).toEqual([[{ x: 0, y: 0, width: 100, height: 100 }]]);
        expect(bandRects([[1]], 1, 0, 1, 'grouped', 0.25)).toEqual([[{ x: 25, y: 0, width: 50, height: 100 }]]);
        // Half the band on each side would leave no bar at all.
        expect(bandRects([[1]], 1, 0, 1, 'grouped', 3)[0][0]?.width).toBe(10);
    });

    it('collapses onto the baseline for a degenerate or non-finite domain instead of emitting NaN', () => {
        for (const [min, max] of [
            [0, 0],
            [5, 5],
            [0, Number.NaN],
            [Number.NEGATIVE_INFINITY, 1],
        ]) {
            for (const mode of ['grouped', 'stacked'] as const) {
                const [rect] = bandRects([[3]], 1, min, max, mode)[0];
                expect(rect, `${min}..${max} ${mode}`).toEqual({ x: 15, y: 100, width: 70, height: 0 });
            }
        }
    });

    it('returns one row per series and no rects when there are no bands', () => {
        expect(bandRects([[1, 2], [3]], 0, 0, 10)).toEqual([[], []]);
        expect(bandRects([], 3, 0, 10)).toEqual([]);
    });

    it('never yields a non-finite number, whatever the values', () => {
        const rects = bandRects([[0, Number.NaN, Number.POSITIVE_INFINITY, 4]], 4, 0, 4).flat();

        expect(rects.map((rect) => rect === null)).toEqual([false, true, true, false]);
        for (const rect of rects) {
            if (rect) expect(Object.values(rect).every((n) => Number.isFinite(n))).toBe(true);
        }
    });
});

describe('arcPath', () => {
    const TAU = 2 * Math.PI;

    it('starts at 12 o\'clock and sweeps clockwise', () => {
        // A quarter: 12 o'clock to 3 o'clock on the outside, back along the hole anticlockwise.
        expect(arcPath(50, 50, 48, 32, 0, TAU / 4)).toBe('M50 2 A48 48 0 0 1 98 50 L82 50 A32 32 0 0 0 50 18 Z');
    });

    it('sets the large-arc flag past a half turn, on both the outer and the inner arc', () => {
        expect(arcPath(50, 50, 48, 32, 0, (3 * TAU) / 4)).toBe('M50 2 A48 48 0 1 1 2 50 L18 50 A32 32 0 1 0 50 18 Z');
        // Exactly half is NOT large: either flag draws the same semicircle, and 0 is the stable choice.
        expect(arcPath(50, 50, 48, 32, 0, TAU / 2)).toBe('M50 2 A48 48 0 0 1 50 98 L50 82 A32 32 0 0 0 50 18 Z');
    });

    it('emits a full circle as two half arcs, with the hole wound the other way', () => {
        // The case that matters: one arc from a point to itself is omitted by the renderer.
        expect(arcPath(50, 50, 48, 32, 0, TAU)).toBe(
            'M50 2 A48 48 0 1 1 50 98 A48 48 0 1 1 50 2 Z M50 18 A32 32 0 1 0 50 82 A32 32 0 1 0 50 18 Z',
        );
    });

    it('treats a slice whose ends ROUND together as full, rather than letting it vanish', () => {
        // 99.999% of the ring: the two ends differ by less than the 0.01 the path is rounded to.
        const nearlyFull = arcPath(50, 50, 48, 32, 0, TAU * 0.99999);

        expect(nearlyFull).toBe(arcPath(50, 50, 48, 32, 0, TAU));
    });

    it('draws a plain pie sector when there is no hole', () => {
        expect(arcPath(50, 50, 48, 0, 0, TAU / 4)).toBe('M50 2 A48 48 0 0 1 98 50 L50 50 Z');
        expect(arcPath(50, 50, 48, 0, 0, TAU)).toBe('M50 2 A48 48 0 1 1 50 98 A48 48 0 1 1 50 2 Z');
    });

    it('returns an empty path for an empty, reversed or non-finite sweep', () => {
        expect(arcPath(50, 50, 48, 32, 1, 1)).toBe('');
        expect(arcPath(50, 50, 48, 32, 2, 1)).toBe('');
        expect(arcPath(50, 50, 0, 0, 0, 1)).toBe('');
        expect(arcPath(50, 50, 48, 32, 0, Number.NaN)).toBe('');
        expect(arcPath(Number.NaN, 50, 48, 32, 0, 1)).toBe('');
    });

    it('never writes NaN or a negative zero into the path', () => {
        for (let i = 1; i <= 24; i++) {
            const d = arcPath(50, 50, 48, 32, ((i - 1) * TAU) / 24, (i * TAU) / 24);
            expect(d).not.toContain('NaN');
            expect(d).not.toContain('-0 ');
        }
    });
});
