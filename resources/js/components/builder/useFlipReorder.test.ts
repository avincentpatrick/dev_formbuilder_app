import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';

import { flipOffset, useFlipReorder } from './useFlipReorder';

/**
 * M150 (`R-adce6e14`) — the glide. happy-dom has no layout, so each node's top is read from a table that the test swaps
 * between the old order and the new; what is checked is where each node is put back before it is let go — read at the
 * moment the composable forces the layout between the two writes.
 */
afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('flipOffset', () => {
    it('is the distance back to the old place, less what the section already moved', () => {
        expect(flipOffset(120, 160)).toBe(-40);
        expect(flipOffset(120, 160, -40)).toBe(0);
        expect(flipOffset(160, 120, -40)).toBe(80);
        // A node that was not there before has nowhere to come from.
        expect(flipOffset(undefined, 160, -40)).toBe(0);
    });
});

function harness(visible = true) {
    const order = ref('before');
    // A node's top in the DOM as rendered for each order: the patch flips `data-order`, so the stub knows which it is.
    const tops = { before: new Map<string, number>(), after: new Map<string, number>() };
    const released = new Map<string, string>();

    const Host = defineComponent({
        setup() {
            const root = ref<HTMLElement | null>(null);
            useFlipReorder(() => root.value, order);
            return () =>
                h('div', { ref: root, 'data-order': order.value }, [
                    h('div', { 'data-group-key': 's1' }, [h('div', { 'data-field-uid': 'a' }), h('div', { 'data-field-uid': 'b' })]),
                ]);
        },
    });

    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        const key = this.dataset.fieldUid ?? this.dataset.groupKey ?? '';
        const phase = this.closest('[data-order]')?.getAttribute('data-order') === 'after' ? tops.after : tops.before;
        return { top: phase.get(key) ?? 0 } as DOMRect;
    });

    const wrapper = mount(Host, { attachTo: document.body });
    const root = wrapper.element as HTMLElement;
    Object.defineProperty(root, 'offsetParent', { get: () => (visible ? document.body : null) });
    // The forced layout between "put back" and "let go" is where the put-back transforms can be seen.
    Object.defineProperty(root, 'offsetHeight', {
        get: () => {
            root.querySelectorAll<HTMLElement>('[data-field-uid], [data-group-key]').forEach((el) =>
                released.set(el.dataset.fieldUid ?? el.dataset.groupKey ?? '', el.style.transform),
            );
            return 0;
        },
    });

    return { order, tops, released, root };
}

describe('useFlipReorder', () => {
    it('starts a moved section from its old place, and its rows only by what they moved within it', async () => {
        const { order, tops, released, root } = harness();
        // Before: the section at 100, a at 120, b at 160. After: the section moved down 40, and a and b swapped in it.
        tops.before.set('s1', 100).set('a', 120).set('b', 160);
        tops.after.set('s1', 140).set('a', 200).set('b', 160);

        order.value = 'after';
        await nextTick();
        await nextTick();

        expect(released.get('s1')).toBe('translateY(-40px)');
        // a: 120 − 200 − (−40) = −40; b: 160 − 160 − (−40) = 40.
        expect(released.get('a')).toBe('translateY(-40px)');
        expect(released.get('b')).toBe('translateY(40px)');
        // And then each is let go, to glide home.
        const a = root.querySelector<HTMLElement>('[data-field-uid="a"]')!;
        expect(a.style.transform).toBe('');
        expect(a.style.transition).toContain('var(--mds-duration-moderate)');
    });

    it('does nothing while the canvas is hidden', async () => {
        const { order, tops, released } = harness(false);
        tops.before.set('s1', 100).set('a', 120).set('b', 160);
        tops.after.set('s1', 140).set('a', 200).set('b', 160);

        order.value = 'after';
        await nextTick();
        await nextTick();

        expect(released.size).toBe(0);
    });
});
