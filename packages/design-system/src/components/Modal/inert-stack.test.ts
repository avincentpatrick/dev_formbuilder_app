import { afterEach, describe, expect, it } from 'vitest';
import { openModalCount, popModalRoot, pushModalRoot } from './inert-stack';

/**
 * The inert/paint-order/scroll-lock stack, tested directly (J6).
 *
 * `Modal.test.ts` has always exercised this module THROUGH `MdsModal`, which is the right level for the
 * dialog behaviour and the wrong level for the question J6 added: the stack now holds two kinds of entry
 * and reports on only one of them. Driving that through a component would mean mounting a dialog to ask a
 * question about a drawer.
 *
 * ⚠️ EVERY CASE HERE MUST POP WHAT IT PUSHES. Specs share one happy-dom document, so a leaked `inert` (or
 * a leaked `body { overflow: hidden }`) silently blanks the NEXT file's assertions — the failure mode
 * `useInertBackground`'s own unmount guard exists to prevent, one level down.
 */

function node(tag = 'div'): HTMLElement {
    const el = document.createElement(tag);
    document.body.appendChild(el);

    return el;
}

afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

describe('openModalCount — dialogs only, which is what it has always documented', () => {
    it('counts a dialog', () => {
        const root = node();

        pushModalRoot(root);
        expect(openModalCount()).toBe(1);

        popModalRoot(root);
        expect(openModalCount()).toBe(0);
    });

    it('does NOT count a surface, though the surface still takes the page', () => {
        // ⭐ THE WHOLE J6 SPLIT, AND THE SECOND HALF IS WHAT KEEPS IT HONEST. A zero here would be
        // satisfiable by never pushing at all, so the case also asserts the background really did go inert
        // — i.e. the entry IS on the stack and is merely reported differently. Together they are the
        // difference between "the drawer is not a dialog" and "the drawer no longer works".
        const background = node('main');
        const root = node();

        pushModalRoot(root, 'surface');

        expect(openModalCount()).toBe(0);
        expect(background.hasAttribute('inert')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');

        popModalRoot(root);
        expect(background.hasAttribute('inert')).toBe(false);
    });

    it('counts only the dialog when a dialog stacks on a surface', () => {
        // The ⌘K-over-the-drawer shape, which is the live case J6 unblocks: the palette must see ONE
        // dialog (its own, once pushed) rather than two, or the chord could never toggle it shut.
        const drawer = node();
        const dialog = node();

        pushModalRoot(drawer, 'surface');
        pushModalRoot(dialog);

        expect(openModalCount()).toBe(1);

        popModalRoot(dialog);
        expect(openModalCount()).toBe(0);
        popModalRoot(drawer);
    });

    it('defaults to dialog, so every MdsModal consumer is unchanged by the split', () => {
        // The default is the compatibility guarantee: 29 call sites pass no kind, and a default of
        // `surface` would silently stop the palette guarding against real dialogs — a mutation this
        // assertion is here to kill.
        const a = node();
        const b = node();

        pushModalRoot(a);
        pushModalRoot(b);
        expect(openModalCount()).toBe(2);

        popModalRoot(a);
        popModalRoot(b);
    });
});

describe('the stack itself', () => {
    it('is idempotent per root, and the FIRST kind wins', () => {
        // ⭐ A re-push under a different kind must not reclassify an entry something else is already
        // reasoning about. The palette reads this count synchronously inside a keydown handler; a root
        // that could change kind under it would make the guard's answer depend on call order.
        const root = node();

        pushModalRoot(root, 'surface');
        pushModalRoot(root, 'dialog');

        expect(openModalCount()).toBe(0);

        popModalRoot(root);
        expect(openModalCount()).toBe(0);
    });

    it('paints by stack depth regardless of kind, so the visually topmost surface is the one that owns it', () => {
        // Paint order is not cosmetic here — see BASE_Z_INDEX. A surface beneath a dialog must still paint
        // beneath it, or hit-testing sends clicks aimed at the dialog through to the wrong backdrop.
        const drawer = node();
        const dialog = node();

        pushModalRoot(drawer, 'surface');
        pushModalRoot(dialog);

        expect(Number(dialog.style.zIndex)).toBeGreaterThan(Number(drawer.style.zIndex));

        popModalRoot(dialog);
        popModalRoot(drawer);
        expect(dialog.style.zIndex).toBe('');
        expect(drawer.style.zIndex).toBe('');
    });

    it('ignores a pop for a root it never held, rather than releasing the topmost one', () => {
        // The not-on-stack guard is load-bearing: `splice(-1, 1)` removes the LAST element, so without it
        // a stray pop silently releases whichever surface currently owns the page.
        const held = node();
        const stranger = node();

        pushModalRoot(held);
        popModalRoot(stranger);

        expect(openModalCount()).toBe(1);

        popModalRoot(held);
        expect(openModalCount()).toBe(0);
    });

    it('keeps the page locked while a surface remains under a closed dialog', () => {
        // The scroll lock is driven by stack DEPTH, not by dialog count — the latent bug I10a fixed for
        // free, and one the kind split must not reintroduce from the other direction.
        const drawer = node();
        const dialog = node();

        pushModalRoot(drawer, 'surface');
        pushModalRoot(dialog);
        popModalRoot(dialog);

        expect(openModalCount()).toBe(0);
        expect(document.body.style.overflow).toBe('hidden');

        popModalRoot(drawer);
        expect(document.body.style.overflow).toBe('');
    });
});
