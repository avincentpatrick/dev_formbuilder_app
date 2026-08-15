/**
 * Tooltip geometry, kept as pure functions on plain rects (J4b).
 *
 * ⚠️ THE PURITY IS THE WHOLE POINT, AND IT IS FORCED BY THE TEST ENVIRONMENT RATHER THAN BY TASTE.
 * Vitest runs on happy-dom, which computes no layout: every `getBoundingClientRect()` returns zeros.
 * A flip-and-shift assertion written against the DOM would therefore pass against *any*
 * implementation, including one that never flips — the vacuous-green shape this repo has been bitten
 * by before. Taking rects in and coordinates out moves the whole decision into a function whose cases
 * are real, and leaves the SFC with only wiring to prove.
 *
 * No CSS anchor positioning: it is not portable across the browser matrix this product supports, and
 * the fallback would need this arithmetic anyway.
 */

export type TooltipPlacement = 'top' | 'right' | 'bottom' | 'left';

export interface AnchorRect {
    top: number;
    left: number;
    width: number;
    height: number;
}

export interface BubbleSize {
    width: number;
    height: number;
}

export interface Viewport {
    width: number;
    height: number;
}

export interface Placed {
    left: number;
    top: number;
    placement: TooltipPlacement;
}

/**
 * The gap between trigger and bubble.
 *
 * ⚠️ THIS CONSTANT AND THE STYLESHEET'S BRIDGE WIDTH ARE ONE NUMBER SPELLED TWICE, AND A TEST HOLDS
 * THEM EQUAL. The bridge is the transparent `::before` that covers this gap so a pointer travelling
 * from trigger to bubble never crosses a dead zone — which is WCAG 1.4.13's *hoverable* clause. If
 * the offset grows past the bridge, the tooltip dismisses itself mid-journey and the failure looks
 * like a flaky hover rather than a two-line mismatch.
 */
export const TOOLTIP_OFFSET = 8;

/** Minimum clearance from every viewport edge, so a bubble never sits flush against the glass. */
export const VIEWPORT_PAD = 8;

const OPPOSITE: Record<TooltipPlacement, TooltipPlacement> = {
    top: 'bottom',
    bottom: 'top',
    left: 'right',
    right: 'left',
};

/** Space available on one side of the anchor, before the offset and the edge pad are spent. */
function room(placement: TooltipPlacement, anchor: AnchorRect, viewport: Viewport): number {
    switch (placement) {
        case 'top':
            return anchor.top;
        case 'bottom':
            return viewport.height - (anchor.top + anchor.height);
        case 'left':
            return anchor.left;
        case 'right':
            return viewport.width - (anchor.left + anchor.width);
    }
}

function fits(placement: TooltipPlacement, anchor: AnchorRect, bubble: BubbleSize, viewport: Viewport): boolean {
    const needed = (placement === 'top' || placement === 'bottom' ? bubble.height : bubble.width)
        + TOOLTIP_OFFSET
        + VIEWPORT_PAD;

    return room(placement, anchor, viewport) >= needed;
}

function clamp(value: number, min: number, max: number): number {
    // `max` can fall below `min` when the bubble is wider than the viewport allows. Preferring `min`
    // keeps the readable edge on screen rather than centring the overflow across both edges.
    return Math.max(min, Math.min(value, max));
}

/**
 * Resolve a preferred side into fixed-position coordinates.
 *
 * Flip, then shift. The flip only happens when the opposite side is genuinely better — a bubble that
 * fits on neither side keeps the side the caller asked for and lets the clamp handle it, because
 * flipping into an equally-bad side moves the tooltip for no gain and makes the placement look random
 * as the viewport resizes.
 */
export function placeTooltip(
    anchor: AnchorRect,
    bubble: BubbleSize,
    viewport: Viewport,
    preferred: TooltipPlacement,
): Placed {
    const placement = fits(preferred, anchor, bubble, viewport) || !fits(OPPOSITE[preferred], anchor, bubble, viewport)
        ? preferred
        : OPPOSITE[preferred];

    if (placement === 'top' || placement === 'bottom') {
        const top = placement === 'top'
            ? anchor.top - bubble.height - TOOLTIP_OFFSET
            : anchor.top + anchor.height + TOOLTIP_OFFSET;

        return {
            placement,
            top,
            left: clamp(
                anchor.left + anchor.width / 2 - bubble.width / 2,
                VIEWPORT_PAD,
                viewport.width - bubble.width - VIEWPORT_PAD,
            ),
        };
    }

    const left = placement === 'left'
        ? anchor.left - bubble.width - TOOLTIP_OFFSET
        : anchor.left + anchor.width + TOOLTIP_OFFSET;

    return {
        placement,
        left,
        top: clamp(
            anchor.top + anchor.height / 2 - bubble.height / 2,
            VIEWPORT_PAD,
            viewport.height - bubble.height - VIEWPORT_PAD,
        ),
    };
}

/**
 * True once the anchor has scrolled entirely out of view.
 *
 * The tooltip hides rather than following, because a `position: fixed` bubble does not scroll with its
 * trigger: without this it would sit in the middle of the viewport describing a control that is no
 * longer on screen. Partial visibility keeps it — a half-visible rail item is still a thing the user
 * is pointing at.
 *
 * ⚠️ A ZERO-AREA RECT MEANS UNMEASURED, NOT ABSENT, AND CONFLATING THE TWO BREAKS THE COMPONENT
 * ENTIRELY. Without the first guard, `top + height <= 0` is satisfied by the all-zeros rect every
 * not-yet-laid-out element reports — so the tooltip becomes visible, measures itself on the next tick,
 * concludes its own trigger is off screen, and hides again in the same frame. It never appears at all.
 *
 * This was found by the suite rather than by the browser, and the way it hid is the part worth keeping:
 * the cases that seed visibility directly all PASSED, because nothing calls this on that path — and
 * seeding visibility is exactly what the axe stories do. The accessibility gate would have gone green
 * over a tooltip that could not be shown by any user action.
 */
export function anchorOffscreen(anchor: AnchorRect, viewport: Viewport): boolean {
    if (anchor.width === 0 && anchor.height === 0) return false;

    return anchor.top + anchor.height <= 0
        || anchor.top >= viewport.height
        || anchor.left + anchor.width <= 0
        || anchor.left >= viewport.width;
}
