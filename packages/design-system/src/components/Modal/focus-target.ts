/**
 * Can this element actually take focus? (J6)
 *
 * ⚠️ IT EXISTS BECAUSE THERE WERE TWO ANSWERS TO ONE QUESTION AND ONE OF THEM WAS INCOMPLETE. The command
 * palette carried a private `isVisible()` for picking the control it focuses before opening, so that
 * `MdsModal` captures a real opener rather than `<body>`; `MdsModal` carried its own after-the-fact
 * verification for the same reason on the way in. Neither knew about `inert`. Two definitions of one
 * predicate is the drift this project keeps paying for — `FeatureAdmission` in J5a was the same shape one
 * layer down — so it is defined once, here, and both sides read it.
 *
 * ── WHY `inert` HAS TO BE PART OF THE ANSWER, WHICH IS THE HALF THAT WAS MISSING ──────────────────────
 * `checkVisibility()` is the honest question about RENDERING — `display`, `visibility`,
 * `content-visibility` — and an inert element renders perfectly normally. So it returns `true` for a
 * control inside an inert subtree, and `.focus()` on that control is a **silent no-op**: no error, no
 * warning, nothing in the console. The palette's opener list was built precisely to stop that class of
 * failure (a single `#topnav-search` stranded focus at 375px, where the field is `display: none`) and was
 * blind to this instance of it: with the mobile nav drawer holding the page, every candidate in the top
 * nav is inert, the resolver hands one back anyway, and the modal captures whatever transient element the
 * drawer happened to have.
 *
 * `closest('[inert]')` rather than the `inert` IDL property, and rather than checking the element alone:
 * `inert` is INHERITED by the whole subtree, so the attribute normally sits on an ancestor and never on
 * the control itself. `inert-stack.ts` sets the attribute for the same reason axe reads it.
 */

/**
 * happy-dom implements neither `checkVisibility()` nor a real `offsetParent` — J1a MEASURED the latter as
 * `undefined` rather than `null`, which is why the fallback treats every candidate as rendered under
 * Vitest rather than as hidden. Getting this backwards would make the unit suite disagree with the browser
 * about every element, and the real guard for the layout half is the e2e spec.
 */
function isRendered(element: HTMLElement): boolean {
    if (typeof element.checkVisibility === 'function') return element.checkVisibility();

    return element.offsetParent !== null;
}

/** Inside an inert subtree — where `.focus()` is a silent no-op — including on the element itself. */
export function isInert(element: HTMLElement): boolean {
    return element.closest('[inert]') !== null;
}

/** Rendered, and not inert. The two ways `.focus()` fails without saying so. */
export function canTakeFocus(element: HTMLElement): boolean {
    return isRendered(element) && !isInert(element);
}

/**
 * The first candidate that can actually take focus, in PREFERENCE order.
 *
 * ⚠️ PREFERENCE ORDER, NOT DOCUMENT ORDER, WHICH IS WHY THIS TAKES A LIST RATHER THAN ONE COMMA-JOINED
 * SELECTOR. `querySelector('a, b')` returns whichever match comes first in the DOCUMENT, so a comma list
 * would silently reorder the consumer's intent — and the one consumer's intent is specifically "the full
 * search field if it is there, otherwise the compact icon", which are two different elements at two
 * different breakpoints.
 *
 * An invalid selector is skipped rather than thrown: `querySelector('[')` raises a DOMException, and both
 * call sites run where an escaping throw would abandon focus management entirely on a page that is already
 * inert.
 *
 * ⚠️ IT WALKS ALL MATCHES OF EACH SELECTOR, NOT JUST THE FIRST, AND THE FIRST VERSION DID NOT — CAUGHT BY
 * THE ADVERSARIAL PASS READING THIS DOCSTRING AGAINST THE CODE. With `querySelector` the behaviour was
 * really *"the first selector whose FIRST match can take focus"*, so a selector matching several elements
 * where the leading one happened to be inert returned null and skipped reachable siblings the caller had
 * plainly asked for. The two shipped call sites pass selectors that match one element each, so nothing was
 * broken — but a docstring that overstates what a helper does is how the next author builds on a promise it
 * does not keep, which is the class this project has corrected twice before in its own docblocks.
 */
export function firstFocusable(selectors: readonly string[], scope: ParentNode = document): HTMLElement | null {
    for (const selector of selectors) {
        let matches: HTMLElement[] = [];

        try {
            matches = Array.from(scope.querySelectorAll<HTMLElement>(selector));
        } catch {
            continue;
        }

        for (const candidate of matches) {
            if (canTakeFocus(candidate)) return candidate;
        }
    }

    return null;
}
