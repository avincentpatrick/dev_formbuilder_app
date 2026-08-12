import { readFileSync } from 'node:fs';
import { join } from 'node:path';
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

/**
 * JR2 — a source-level guard for a defect that shipped in this very increment and that NO runtime
 * gate in this repo could have caught.
 *
 * When the column header became uppercase and tracked, `Status` and `Version` obeyed and `Form` and
 * `Updated` did not: those two are the SORTABLE columns, and their text lives inside
 * `.mds-table__sort`, a `<button>`. `font: inherit` resets only the `font-*` longhands; the UA
 * stylesheet separately sets `text-transform: none` and `letter-spacing: normal` on form controls,
 * and those beat inheritance. One header row rendered in two type treatments.
 *
 * It is asserted as SOURCE TEXT on purpose. happy-dom computes no styles, so a `getComputedStyle`
 * assertion would pass whatever the CSS said; axe does not check letter case; and there is no
 * visual-regression baseline anywhere in the repo. The only thing that noticed was a person looking
 * at a screenshot, and a person is not a gate. `token-references.test.ts` already reads `.vue` files
 * as text for the same reason.
 */
describe('MdsDataTable — the sort button must not drop the header’s type treatment', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/DataTable/DataTable.vue'),
        'utf8',
    );

    const sortRule = source.match(/\.mds-table__sort\s*\{([^}]*)\}/);
    const thRule = source.match(/\.mds-table__th\s*\{([^}]*)\}/);

    it('inherits every type property the header sets that a <button> would otherwise reset', () => {
        expect(sortRule, '.mds-table__sort rule not found — was it renamed?').not.toBeNull();
        expect(thRule, '.mds-table__th rule not found — was it renamed?').not.toBeNull();

        // Exactly the two properties the UA sheet resets on form controls and `font:` does not cover.
        for (const property of ['text-transform', 'letter-spacing'] as const) {
            if (!thRule![1].includes(`${property}:`)) {
                continue; // the header no longer sets it, so the button has nothing to inherit
            }

            expect(
                sortRule![1],
                `.mds-table__th sets ${property} but .mds-table__sort does not inherit it — the ` +
                    'sortable columns will render differently from the rest of the header row',
            ).toContain(`${property}: inherit`);
        }
    });

    it('is not vacuous: the header really does set both properties today', () => {
        expect(thRule![1]).toMatch(/text-transform:\s*uppercase/);
        expect(thRule![1]).toMatch(/letter-spacing:\s*var\(--mds-tracking-wide\)/);
    });
});
