import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { __resetCommandPaletteBindings, useCommandPalette } from '../useCommandPalette';

/**
 * The ⌘K chord (Increment J1d, DSR §3.4.1).
 *
 * ⚠️ THE GUARD-ORDER CASES ARE THE POINT. `openModalCount()` lags `open` by one tick (MdsModal pushes onto
 * the inert stack inside `nextTick`), so a handler that checked the count first could never toggle the
 * palette shut — it would see its own dialog as "someone else's". These cases pin the order that resolves
 * it without an `await`, which matters because awaiting would surrender `preventDefault()`'s synchronous
 * window and hand the keystroke to the browser.
 *
 * ⚠️ WHAT THIS FILE STRUCTURALLY CANNOT SEE, STATED SO NOBODY READS IT AS COVERAGE IT DOES NOT HAVE: it
 * MOCKS `openModalCount`, so J6's dialog-vs-surface split is invisible here — a case asserting "the chord
 * works while a drawer holds the page" would pass with the split reverted, because the mock answers
 * whatever it is told. That behaviour is pinned against the REAL implementation in
 * `packages/design-system/src/components/Modal/inert-stack.test.ts` and `useInertBackground.test.ts`.
 * What does belong here is the guard ORDER and the `preventDefault()` POSITION, which are this file's own.
 */

const openModalCount = vi.hoisted(() => vi.fn(() => 0));

vi.mock('@meridian/design-system', () => ({ openModalCount }));

function mountHost(opener?: () => HTMLElement | null) {
    const Host = defineComponent({
        setup() {
            const { open } = useCommandPalette(opener);

            return { open };
        },
        render() {
            return h('div');
        },
    });

    return mount(Host, { attachTo: document.body });
}

function chord(key = 'k'): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key, metaKey: true, cancelable: true, bubbles: true });
    document.dispatchEvent(event);

    return event;
}

beforeEach(() => {
    __resetCommandPaletteBindings();
    openModalCount.mockReturnValue(0);
});

afterEach(() => {
    __resetCommandPaletteBindings();
});

describe('useCommandPalette', () => {
    it('opens on the chord', () => {
        const host = mountHost();

        chord();

        expect(host.vm.open).toBe(true);
        host.unmount();
    });

    it('closes on the chord while open, which the count guard alone could never do', () => {
        const host = mountHost();

        chord();
        expect(host.vm.open).toBe(true);

        // Simulate the state MdsModal reaches once it has pushed onto the inert stack: our own palette now
        // counts as an open modal. If the handler checked the count before its own state, this would be a
        // no-op and the palette could never be toggled shut.
        openModalCount.mockReturnValue(1);
        chord();

        expect(host.vm.open).toBe(false);
        host.unmount();
    });

    it('is a no-op while somebody else’s dialog is open', async () => {
        const host = mountHost();

        openModalCount.mockReturnValue(1);
        await nextTick();
        chord();

        expect(host.vm.open).toBe(false);
        host.unmount();
    });

    it('still swallows the chord when it declines over a real dialog, rather than handing it back', () => {
        // ⭐ The other half of J6, and the reason `preventDefault()` stays ABOVE the guards. The naive fix
        // for the dead key was to move it below them — which would open the BROWSER's find bar on top of an
        // open modal. Declining silently is the correct outcome there; the defect was only ever that a
        // drawer was being counted as a dialog.
        const host = mountHost();

        openModalCount.mockReturnValue(1);
        const event = chord();

        expect(host.vm.open).toBe(false);
        expect(event.defaultPrevented).toBe(true);

        host.unmount();
    });

    it('calls preventDefault, because browsers bind the same chord to their address bar', () => {
        const host = mountHost();

        const event = chord();

        expect(event.defaultPrevented).toBe(true);
        host.unmount();
    });

    it('fires from inside a text field, deliberately', () => {
        const host = mountHost();

        const input = document.createElement('textarea');
        document.body.appendChild(input);
        input.focus();

        const event = new KeyboardEvent('keydown', { key: 'k', metaKey: true, cancelable: true, bubbles: true });
        input.dispatchEvent(event);

        // A modifier chord does not collide with typing, and the builder's editors are exactly where a
        // user wants it — DSR §3.4.1 states there is deliberately no tag guard.
        expect(host.vm.open).toBe(true);

        input.remove();
        host.unmount();
    });

    it('reaches the handler even when a page stops propagation, because the listener is capture-phase', () => {
        const host = mountHost();

        const trap = document.createElement('div');
        document.body.appendChild(trap);
        trap.addEventListener('keydown', (e) => e.stopPropagation());

        trap.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, cancelable: true, bubbles: true }));

        expect(host.vm.open).toBe(true);

        trap.remove();
        host.unmount();
    });

    it('ignores a bare k and a chord with alt held', () => {
        const host = mountHost();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', bubbles: true }));
        expect(host.vm.open).toBe(false);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, altKey: true, bubbles: true }));
        expect(host.vm.open).toBe(false);

        host.unmount();
    });

    it('focuses a real opener before opening, so the modal never captures <body>', () => {
        const button = document.createElement('button');
        document.body.appendChild(button);
        const host = mountHost(() => button);

        chord();

        expect(document.activeElement).toBe(button);

        button.remove();
        host.unmount();
    });

    it('binds once however many times it is mounted', () => {
        const a = mountHost();
        const b = mountHost();

        chord();

        // Two capture listeners would toggle twice per keypress and leave it closed — which reads to a
        // user as "the shortcut does nothing".
        expect(a.vm.open).toBe(true);

        a.unmount();
        b.unmount();
    });

    it('removes its capture listener on unmount, which is a real leak if the flag is dropped', () => {
        const host = mountHost();
        host.unmount();

        // ⚠️ removeEventListener matches on (type, handler, capture). A capture listener removed WITHOUT
        // the flag is never removed at all — the chord would keep firing against an unmounted component
        // forever, and nothing else in the suite would show it.
        const spy = vi.spyOn(document, 'addEventListener');
        chord();
        spy.mockRestore();

        const fresh = mountHost();
        expect(fresh.vm.open).toBe(false);

        chord();
        expect(fresh.vm.open).toBe(true);
        fresh.unmount();
    });
});
