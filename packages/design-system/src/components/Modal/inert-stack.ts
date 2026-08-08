/**
 * The modal `inert` + paint-order + scroll-lock stack (Increment I10a, closing `docs/feature-backlog.md`'s
 * "MdsModal should mark the rest of the page inert" row).
 *
 * `MdsModal` renders a scrim and traps Tab, but until I10a it left the rest of the page **reachable**:
 * focus order still walked it and a screen reader still announced it (WCAG 2.4.3). The Tab trap could not
 * fix that on its own — it is a `keydown` handler bound to the panel, so it only ever sees Tab events whose
 * target is already inside the dialog. Anything that puts focus outside (a browser-chrome round trip, a
 * screen reader's virtual cursor, a programmatic `focus()` from the background page) escapes it entirely.
 * That is structural. `inert` is the fix.
 *
 * Module scope rather than per-component, because three things here are page-global:
 *
 *  1. **The stack.** `MdsModal` is stackable in practice: `Builder.vue`'s `ConflictDialog` is ALWAYS
 *     mounted and can open from a background PATCH while the schedule / confirm / import / share modal is
 *     already up. Per-instance bookkeeping would let the first close release `inert` (and the scroll lock —
 *     the same latent bug this fixes for free) out from under the second.
 *  2. **Paint order.** See `BASE_Z_INDEX` — this is the half that makes the stack *correct* rather than
 *     merely tidy, and it is not optional.
 *  3. **`document.body.style.overflow`.** One page, one lock, driven by stack depth.
 *
 * ── THE WALK IS AN ANCESTOR-SIBLING WALK, AND "EVERY BODY CHILD EXCEPT OURS" IS A TRAP ─────────────────
 * With `teleport: true` (the default, and what every app consumer uses) the backdrop is a sibling of `#app`
 * under `<body>`, so "the rest of the page" really is the other body children. But with `teleport: false` —
 * which is how every Storybook story renders, so that the axe runner scoped to `#storybook-root` can see the
 * dialog at all — the backdrop renders INSIDE the story root. The naive rule would then set `inert` on
 * `#storybook-root` itself, and `checkA11y(page, '#storybook-root')` would scan an empty accessibility tree
 * and **pass silently**. A green a11y job over nothing is worse than a red one. So: walk from the backdrop
 * up to `<body>` and, at each level, mark only the siblings that are not on the path. Correct in both
 * teleport modes, and it can never touch an ancestor of the dialog.
 *
 * ── WHAT GOING INERT COSTS, STATED RATHER THAN DISCOVERED LATER ────────────────────────────────────────
 * `inert` removes the background from the accessibility tree, so **every `aria-live` region outside the
 * dialog stops announcing for as long as the modal is open**, and nothing replays when it closes. That is
 * native `<dialog>` behaviour and it is the intended trade — an announcement about content the user cannot
 * reach is noise — but it means a surface that must keep talking across a modal has to be a body-level
 * portal carrying `EXEMPT_ATTR`. `MdsToastHost` is the only one today, and that is exactly why it is one.
 */

/**
 * Opted-out of `inert`, for a portal that must stay reachable across an open modal — see `ToastHost.vue`.
 *
 * ⚠️ THIS ONLY WORKS ON AN ELEMENT THE WALK ACTUALLY VISITS, i.e. an off-path sibling at some level of the
 * walk. It is not a general "never inert me" marker: `inert` is inherited by the whole subtree, so an exempt
 * element nested INSIDE a marked sibling still ends up inert and the attribute does nothing, silently. That
 * is why a portal is the only sane consumer — a portal is a body-level sibling by construction.
 */
const EXEMPT_ATTR = 'data-mds-inert-exempt';

/**
 * `.mds-modal__backdrop`'s own `z-index` (Modal.vue). Each open modal is raised from here by its stack
 * depth, which stays clear of `.mds-toast-host`'s 1100 at any realistic depth.
 *
 * ⚠️ RAISING IT IS NOT COSMETIC — IT IS WHAT MAKES `inert` CORRECT, AND DELETING IT REINTRODUCES THIS
 * INCREMENT'S OWN DEFECT, INVERTED. Every backdrop paints at the same z-index in the root stacking context,
 * so paint order falls back to DOM order — and for a teleported modal, DOM order is fixed at MOUNT time by
 * template declaration order, not by open order: Vue appends each `<Teleport>`'s anchors to the target when
 * the Teleport itself mounts (`prepareAnchor`, called from `mountToTarget` with a null anchor) and never
 * re-anchors on a later patch. `Builder.vue` declares `ConflictDialog` first and `ShareModal` fifth, and
 * mounts both unconditionally, so a field PATCH returning 409 while the Share dialog is open opens the
 * conflict dialog SECOND — later in the stack, but EARLIER in `<body>`. Without this raise the walk would
 * mark the Share backdrop, i.e. inert the dialog the user is looking at while leaving the invisible one
 * live: hit-testing treats an inert subtree as `pointer-events: none`, so clicks aimed at the visible dialog
 * fall through to a hidden full-viewport backdrop (and `onBackdrop` → `requestClose` on the wrong modal),
 * while the visible dialog is the one removed from the accessibility tree.
 *
 * Raising the top of the stack makes paint order agree with the stack, which is what native `<dialog>`'s
 * top layer does for free. Found by adversarial review, not by the suite — the stacking case mounts both
 * modals already open, so DOM order and open order can never disagree there. There is now a case that
 * opens them in reverse declaration order specifically to hold this.
 */
const BASE_Z_INDEX = 1000;

/** Nothing rendered, nothing focusable — marking these would only clutter the DOM for an inspector. */
const NON_RENDERED = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'NOSCRIPT', 'TEMPLATE', 'TITLE', 'BASE']);

/** Open modal roots (backdrop elements), oldest first. The last entry owns the page. */
const stack: HTMLElement[] = [];

/** Exactly what WE set `inert` on, so cleanup can never strip a consumer's own pre-existing `inert`. */
let marked = new Set<Element>();

function skip(el: Element): boolean {
    return NON_RENDERED.has(el.tagName) || el.hasAttribute(EXEMPT_ATTR);
}

/**
 * Every element that is neither the dialog, nor an ancestor of it, nor exempt — walking up to `<body>`.
 *
 * Terminates on a detached root too (`parentElement` reaches null), returning an empty list rather than
 * looping: a root whose tree is not rooted at `document.body` has no background to speak of.
 */
function backgroundOf(root: HTMLElement): Element[] {
    const out: Element[] = [];
    let node: HTMLElement = root;

    while (node.parentElement !== null && node !== document.body) {
        for (const sibling of Array.from(node.parentElement.children)) {
            if (sibling !== node && !skip(sibling)) out.push(sibling);
        }
        node = node.parentElement;
    }

    return out;
}

function release(): void {
    for (const el of marked) el.removeAttribute('inert');
    marked = new Set();
}

function apply(): void {
    // Release FIRST: with two stacked modals the lower backdrop is itself one of our marks, and the
    // pre-existing check below would otherwise mistake our own attribute for the consumer's.
    release();

    const top = stack[stack.length - 1];
    if (top === undefined) return;

    for (const el of backgroundOf(top)) {
        if (el.hasAttribute('inert')) continue; // the consumer's own; leave it, and do not claim it
        // `setAttribute`, not the `el.inert` IDL property, because axe-core reads the ATTRIBUTE
        // (`vNode.hasAttr('inert')`) — and the attribute is what every engine agrees on.
        el.setAttribute('inert', '');
        marked.add(el);
    }
}

/** Make the visually topmost dialog the one the stack calls top. See `BASE_Z_INDEX` — load-bearing. */
function applyPaintOrder(): void {
    stack.forEach((root, depth) => {
        root.style.zIndex = String(BASE_Z_INDEX + depth);
    });
}

function syncScrollLock(): void {
    document.body.style.overflow = stack.length > 0 ? 'hidden' : '';
}

/** An opening modal takes the page. Idempotent — a re-push of the same root is a no-op. */
export function pushModalRoot(root: HTMLElement): void {
    if (stack.includes(root)) return;
    stack.push(root);
    applyPaintOrder();
    apply();
    syncScrollLock();
}

/**
 * A closing (or unmounting) modal gives the page back — or hands it to the modal beneath.
 *
 * ⚠️ CALL THIS BEFORE RESTORING FOCUS TO THE OPENER. `HTMLElement.focus()` on an element inside an inert
 * subtree is a silent no-op, so the reverse order breaks return-focus in every modal in the product with no
 * error and nothing in the console.
 *
 * Note the ordering contract only *delivers* focus when the stack empties. If a LOWER modal closes while an
 * upper one is still open, `apply()` re-marks the background for the new top before returning, so the
 * caller's `focus()` lands inside an inert subtree and does nothing — which is correct, not a bug: focus
 * must not jump to a control sitting behind an open dialog. The remaining dialog keeps it.
 *
 * The not-on-stack guard is load-bearing, not defensive: `splice(-1, 1)` removes the LAST element, so
 * without it a stray pop would silently release the TOPMOST modal instead of doing nothing.
 */
export function popModalRoot(root: HTMLElement): void {
    const at = stack.indexOf(root);
    if (at === -1) return;
    stack.splice(at, 1);
    root.style.zIndex = '';
    applyPaintOrder();
    apply();
    syncScrollLock();
}

/** Test seam — the depth is otherwise only observable through its side effects. */
export function openModalCount(): number {
    return stack.length;
}
