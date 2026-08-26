/**
 * The arithmetic that keeps `MdsCombobox`'s highlight inside its own scroll box (M20, DSR §3.4.1).
 *
 * ── WHY THIS IS A MODULE AND NOT FOUR LINES INSIDE THE SFC ─────────────────────────────────────────────
 * happy-dom computes no layout, so every box this function reasons about is `0` in the unit environment
 * and a mounted assertion would pass whatever the component did. Lifting the arithmetic out is the only
 * shape this repository can actually gate — the same move `Modal/focus-target.ts` and
 * `PasswordStrength/describedby.ts` already make, for the same reason.
 *
 * ── ⛔ WHY NOT `scrollIntoView`, WHICH IS THE ONE-LINE VERSION OF THIS FILE ──────────────────────────────
 * `Element.scrollIntoView({ block: 'nearest' })` is specified to walk **every** scrollable ancestor of the
 * element, not only the nearest one. In this component the listbox sits inside a dialog body inside the
 * page, so the browser is free to scroll the dialog and the document as well — and a page that gains
 * scroll it should not have is precisely what M17 and M19 spent two increments removing. Computing a new
 * `scrollTop` and assigning it can only ever move the one box that is supposed to move.
 *
 * ── THE CONTRACT ───────────────────────────────────────────────────────────────────────────────────────
 * `null` means *already visible, do not write*. That is not a micro-optimisation: assigning `scrollTop`
 * unconditionally on every keystroke fights a user who is scrolling the list with a wheel or a trackpad,
 * and it cancels momentum scrolling on touch. The caller writes only when this function says to.
 */

export interface RevealGeometry {
    /** The scroll container's current offset — `list.scrollTop`. */
    scrollTop: number;
    /**
     * The scroll container's visible height — `list.clientHeight`, which already excludes its borders and
     * any horizontal scrollbar. Using `offsetHeight` here would over-report the visible band by the border
     * width and leave the highlight a hairline short of visible at the bottom edge.
     */
    viewportHeight: number;
    /**
     * The option's top edge in the container's **scrollable content** coordinates — i.e. measured from the
     * top of the content, not from the top of the visible band. A caller working from
     * `getBoundingClientRect()` gets there by adding the container's current `scrollTop` back on.
     */
    optionTop: number;
    optionHeight: number;
}

/**
 * The `scrollTop` that brings `option` fully inside the visible band, or `null` when it already is.
 *
 * Moves by the least amount that works — an option above the band is aligned to the top, one below it to
 * the bottom — so the reader's mental picture of the list survives an arrow keypress. Centring instead
 * would re-lay the whole list on every press.
 */
export function scrollTopToReveal(geometry: RevealGeometry): number | null {
    const { scrollTop, viewportHeight, optionTop, optionHeight } = geometry;

    // ⚠️ NOT DEFENSIVENESS — THIS IS THE UNIT ENVIRONMENT AND THE CLOSED-LIST CASE. happy-dom reports 0
    // for every box, and a `display: none` ancestor does the same in a real browser. Both would otherwise
    // compute `optionTop + optionHeight - 0`, i.e. scroll the list to the bottom for no reason, and in
    // happy-dom that silently "passes" a test that meant to assert nothing happened.
    if (viewportHeight <= 0) return null;

    // An option taller than the band can never be "fully visible", so the rules below would oscillate
    // between the two edges as the reader arrows past it. Show its START, which is where its label is.
    if (optionHeight >= viewportHeight) {
        return optionTop === scrollTop ? null : Math.max(0, optionTop);
    }

    if (optionTop < scrollTop) return Math.max(0, optionTop);

    const optionBottom = optionTop + optionHeight;
    const bandBottom = scrollTop + viewportHeight;

    if (optionBottom > bandBottom) return Math.max(0, optionBottom - viewportHeight);

    return null;
}
