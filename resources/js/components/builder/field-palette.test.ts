import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import FieldPalette from './FieldPalette.vue';

// The palette's own scroll region must be reachable by keyboard (WCAG 2.1.1 / axe
// `scrollable-region-focusable`). It normally satisfies the rule through its 31 add-buttons — but
// `:disabled="saving"` makes every one of them non-focusable while a debounced save is in flight, and a
// scroll region with no focusable content and no tab stop of its own leaves the field types below the fold
// unreachable without a pointer. That race is what made `builder-axe` intermittently red on `main`.
//
// Like `DataTable.vue`, the component MEASURES rather than assuming, so a palette that fits adds no tab
// stop — both halves are pinned here, including the disabled case, which is the one that actually fired.
//
// happy-dom lays nothing out, so `scrollHeight`/`clientHeight` are both 0 and computed styles are empty.
// Overflow is therefore simulated explicitly rather than produced.

const palette = [
    {
        category: 'text',
        label: 'Text',
        icon: 'text',
        types: [
            { value: 'short_text', label: 'Short text', advanced: false, has_options: false, config_editor: null },
            { value: 'long_text', label: 'Long text', advanced: false, has_options: false, config_editor: null },
        ],
    },
];

/** Make the palette root report itself as an overflowing `overflow-y: auto` box. */
function simulateOverflow(el: Element, scrollHeight: number, clientHeight: number): void {
    Object.defineProperty(el, 'scrollHeight', { value: scrollHeight, configurable: true });
    Object.defineProperty(el, 'clientHeight', { value: clientHeight, configurable: true });
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ overflowY: 'auto' } as CSSStyleDeclaration);
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('FieldPalette — scrollable region keyboard access', () => {
    it('adds no tab stop when the palette fits its pane', () => {
        const wrapper = mount(FieldPalette, { props: { palette } });
        const root = wrapper.find('.palette');

        expect(root.attributes('tabindex')).toBeUndefined();
        expect(root.attributes('role')).toBeUndefined();
        expect(root.attributes('aria-label')).toBeUndefined();

        wrapper.unmount();
    });

    it('becomes a focusable, named group once it actually scrolls', async () => {
        const wrapper = mount(FieldPalette, { props: { palette } });
        simulateOverflow(wrapper.find('.palette').element, 900, 400);

        // Re-measure the way a resize would (happy-dom fires no ResizeObserver callbacks).
        await wrapper.setProps({ palette: [...palette] });
        await new Promise((resolve) => queueMicrotask(() => resolve(null)));

        const root = wrapper.find('.palette');
        expect(root.attributes('tabindex')).toBe('0');
        // `group`, not `region`: a landmark here would compete with the builder page's own.
        expect(root.attributes('role')).toBe('group');
        expect(root.attributes('aria-label')).toBe('Add a field');

        wrapper.unmount();
    });

    it('stays keyboard-reachable while every add-button is disabled — the case that fired', async () => {
        // A debounced save is in flight: all buttons are non-focusable, so the region's OWN tab stop is the
        // only keyboard access to the field types below the fold.
        const wrapper = mount(FieldPalette, { props: { palette, disabled: true } });
        simulateOverflow(wrapper.find('.palette').element, 900, 400);

        await wrapper.setProps({ palette: [...palette] });
        await new Promise((resolve) => queueMicrotask(() => resolve(null)));

        expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.find('.palette').attributes('tabindex')).toBe('0');

        wrapper.unmount();
    });
});
