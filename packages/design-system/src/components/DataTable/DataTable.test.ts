import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DataTable from './DataTable.vue';

// A horizontally scrolling table must be reachable by keyboard, or the columns past the right edge are
// simply unreadable without a pointer (WCAG 2.1.1 / axe `scrollable-region-focusable`). The component
// MEASURES rather than assuming, so a table that fits adds no tab stop — both halves are pinned here.
//
// happy-dom does not lay anything out, so `scrollWidth`/`clientWidth` are both 0 and computed styles are
// empty. Overflow is therefore simulated explicitly rather than produced.

const columns = [
    { key: 'name', header: 'Name' },
    { key: 'status', header: 'Status' },
];

const rows = [
    { id: '1', name: 'Ops feed', status: 'active' },
    { id: '2', name: 'Clinic feed', status: 'paused' },
];

/** Make the scroll wrapper report itself as an overflowing `overflow-x: auto` box. */
function simulateOverflow(el: Element, scrollWidth: number, clientWidth: number): void {
    Object.defineProperty(el, 'scrollWidth', { value: scrollWidth, configurable: true });
    Object.defineProperty(el, 'clientWidth', { value: clientWidth, configurable: true });
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ overflowX: 'auto' } as CSSStyleDeclaration);
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('MdsDataTable — scrollable region keyboard access', () => {
    it('adds no tab stop when the table fits its container', () => {
        const wrapper = mount(DataTable, { props: { columns, rows, caption: 'Delivery rules' } });
        const scroll = wrapper.find('.mds-table__scroll');

        expect(scroll.attributes('tabindex')).toBeUndefined();
        expect(scroll.attributes('role')).toBeUndefined();
        expect(scroll.attributes('aria-label')).toBeUndefined();

        wrapper.unmount();
    });

    it('becomes a focusable, named region once it actually scrolls', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows, caption: 'Delivery rules' } });
        const el = wrapper.find('.mds-table__scroll').element;

        simulateOverflow(el, 900, 400);

        // Re-measures when the rows change (the same path a resize takes).
        await wrapper.setProps({ rows: [...rows, { id: '3', name: 'Archive feed', status: 'disabled' }] });
        await new Promise((resolve) => queueMicrotask(() => resolve(null)));
        await wrapper.vm.$nextTick();

        const scroll = wrapper.find('.mds-table__scroll');

        expect(scroll.attributes('tabindex')).toBe('0');
        // `group`, not `region`: a page can render several tables sharing a caption (Integrations draws one
        // per connected workspace), and duplicate same-named landmarks are their own axe failure.
        expect(scroll.attributes('role')).toBe('group');
        // Named from the required caption, so a screen-reader user knows WHICH table they have entered.
        expect(scroll.attributes('aria-label')).toBe('Delivery rules');

        wrapper.unmount();
    });

    it('stays inert when content is wider but the box does not scroll (the mobile card layout)', async () => {
        // At <=480px the component drops `overflow-x` to `visible`, so it is not a scroll container at all
        // and must not claim a tab stop even though the content is wider than the box.
        const wrapper = mount(DataTable, { props: { columns, rows, caption: 'Delivery rules' } });
        const el = wrapper.find('.mds-table__scroll').element;

        Object.defineProperty(el, 'scrollWidth', { value: 900, configurable: true });
        Object.defineProperty(el, 'clientWidth', { value: 400, configurable: true });
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({ overflowX: 'visible' } as CSSStyleDeclaration);

        await wrapper.setProps({ rows: [...rows] });
        await new Promise((resolve) => queueMicrotask(() => resolve(null)));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.mds-table__scroll').attributes('tabindex')).toBeUndefined();

        wrapper.unmount();
    });
});
