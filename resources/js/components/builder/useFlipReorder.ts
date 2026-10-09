// The glide behind a reorder in the builder canvas (M150, `R-adce6e14`) — FLIP: measure every row and section where it
// IS, let Vue move the nodes, measure where they landed, start each one where it was and transition it home.
//
// ── WHY NOT <TransitionGroup> ─────────────────────────────────────────────────────────────────────────────────────────────
// Each section renders its own list, so a question dragged into another section is a LEAVE in one group and an ENTER in
// the other: in CSS mode the leaving row stays in the layout for two frames (a duplicate under the pointer), and the new
// one does not glide at all. A section's rows sit inside a section that is itself moving, and nested groups each count
// that shift as their own. Vue Test Utils also stubs it. One pass over the whole canvas, keyed by uid, has none of that:
// the same uid in a new element is still one row, and a row's offset is taken net of its section's.
//
// The "first" positions are VISUAL (`getBoundingClientRect` includes a transform in flight), so a row that moves again
// mid-glide starts from where the eye sees it. Reduced motion needs nothing here: the design system collapses every
// duration token to 1ms.
//
// M151 (`R-2baef8ea`): the preview names its rows and sections by other attributes, and its rows are NEW ELEMENTS after a
// move — the engine host is remounted — which this handles as it handles a question moved into another section: a row is
// matched by its key, never by its node.

import { watch, type WatchSource } from 'vue';

const TRANSITION = 'transform var(--mds-duration-moderate) var(--mds-ease-standard)';

type Positions = { rows: Map<string, number>; groups: Map<string, number> };

/** The attributes that name a row and the group it moves inside. Structure's are the defaults. */
export interface FlipAttributes {
    rowAttr: string;
    groupAttr: string;
}

const CANVAS: FlipAttributes = { rowAttr: 'data-field-uid', groupAttr: 'data-group-key' };

function measure(root: HTMLElement, { rowAttr, groupAttr }: FlipAttributes): Positions {
    const rows = new Map<string, number>();
    const groups = new Map<string, number>();
    root.querySelectorAll<HTMLElement>(`[${rowAttr}]`).forEach((el) => rows.set(el.getAttribute(rowAttr) ?? '', el.getBoundingClientRect().top));
    root.querySelectorAll<HTMLElement>(`[${groupAttr}]`).forEach((el) => groups.set(el.getAttribute(groupAttr) ?? '', el.getBoundingClientRect().top));
    return { rows, groups };
}

/** Where a node starts its glide: its old place, less what its section already accounts for. */
export function flipOffset(first: number | undefined, last: number, groupDelta = 0): number {
    return first === undefined ? 0 : first - last - groupDelta;
}

export function useFlipReorder(getRoot: () => HTMLElement | null, order: WatchSource<string>, attributes: FlipAttributes = CANVAS): void {
    const { rowAttr, groupAttr } = attributes;
    let first: Positions | null = null;

    // Before the patch: the DOM still shows the old order.
    watch(
        order,
        () => {
            const root = getRoot();
            first = root && root.offsetParent !== null ? measure(root, attributes) : null;
        },
        { flush: 'pre' },
    );

    // After the patch: put every moved node back where it was, then let it go.
    watch(
        order,
        () => {
            const root = getRoot();
            const before = first;
            first = null;
            // A canvas hidden by `v-show` was not measured before the patch, so there is nothing to put back.
            if (!root || !before) return;

            const groupEls = Array.from(root.querySelectorAll<HTMLElement>(`[${groupAttr}]`));
            const rowEls = Array.from(root.querySelectorAll<HTMLElement>(`[${rowAttr}]`));
            for (const el of [...groupEls, ...rowEls]) {
                el.style.transition = 'none';
                el.style.transform = '';
            }

            const moved: HTMLElement[] = [];
            const groupDelta = new Map<string, number>();
            for (const el of groupEls) {
                const key = el.getAttribute(groupAttr) ?? '';
                const dy = flipOffset(before.groups.get(key), el.getBoundingClientRect().top);
                groupDelta.set(key, dy);
                if (dy !== 0) {
                    el.style.transform = `translateY(${dy}px)`;
                    moved.push(el);
                }
            }
            for (const el of rowEls) {
                const group = el.closest<HTMLElement>(`[${groupAttr}]`)?.getAttribute(groupAttr) ?? '';
                const dy = flipOffset(before.rows.get(el.getAttribute(rowAttr) ?? ''), el.getBoundingClientRect().top, groupDelta.get(group));
                if (dy !== 0) {
                    el.style.transform = `translateY(${dy}px)`;
                    moved.push(el);
                }
            }
            if (moved.length === 0) return;

            // Commit the inverted positions before releasing them, or the browser merges both writes into one frame.
            void root.offsetHeight;
            for (const el of moved) {
                el.style.transition = TRANSITION;
                el.style.transform = '';
            }
        },
        { flush: 'post' },
    );
}
