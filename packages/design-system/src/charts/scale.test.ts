import { describe, expect, it } from 'vitest';
import { areaPath, bandPositions, coord, linePath, niceTicks, scaleLinear, strideLabels } from './scale';

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
