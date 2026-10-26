/**
 * M187 (`R-af2d278b`) — the signature pad's geometry: where a pointer lands on the pad, and what counts as a signature.
 */
import { describe, expect, it } from 'vitest';

import { hasInk, inkLength, toPadPoint } from './signature-canvas';

const PAD = { left: 10, top: 20, width: 300, height: 100 };

describe('signature-canvas (M187)', () => {
    it('stores a point as a fraction of the pad, so a resized pad redraws the same signature', () => {
        expect(toPadPoint(160, 70, PAD)).toEqual({ x: 0.5, y: 0.5 });
    });

    it('keeps a stroke that runs off the pad on its edge', () => {
        expect(toPadPoint(-50, 500, PAD)).toEqual({ x: 0, y: 1 });
    });

    it('survives a pad that has not been laid out yet', () => {
        expect(toPadPoint(0, 0, { left: 0, top: 0, width: 0, height: 0 })).toEqual({ x: 0, y: 0 });
    });

    it('counts a vertical stroke a third as long as a horizontal one, because the pad is 3:1', () => {
        expect(inkLength([[{ x: 0, y: 0 }, { x: 0, y: 0.3 }]])).toBeCloseTo(0.1);
        expect(inkLength([[{ x: 0, y: 0 }, { x: 0.3, y: 0 }]])).toBeCloseTo(0.3);
    });

    it('does not take a tap or a dot for a signature', () => {
        expect(hasInk([])).toBe(false);
        expect(hasInk([[{ x: 0.4, y: 0.4 }]])).toBe(false);
        expect(hasInk([[{ x: 0.4, y: 0.4 }, { x: 0.405, y: 0.4 }]])).toBe(false);
        expect(hasInk([[{ x: 0.1, y: 0.5 }, { x: 0.6, y: 0.5 }]])).toBe(true);
    });
});
