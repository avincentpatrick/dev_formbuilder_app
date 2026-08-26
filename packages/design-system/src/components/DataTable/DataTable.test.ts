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

    it('stays inert when content is wider but the box does not scroll (the card layout)', async () => {
        // Below the collapse threshold the component drops `overflow-x` to `visible`, so it is not a
        // scroll container at all and must not claim a tab stop even though the content is wider.
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

    // ⚠️ ANCHORED TO THE START OF A LINE (`/m`), WHICH THE FIRST VERSION OF THIS GUARD WAS NOT. JR4 added
    // a container block whose selectors are compounds — `.mds-table__frame--stackable .mds-table__th` —
    // and an unanchored pattern matches the TAIL of one, so a block authored above the base rules would
    // silently retarget this test at the stacked declarations, where it would either fail for the wrong
    // reason or pass vacuously. Base rules sit at column 0; everything inside an at-rule is indented.
    const sortRule = source.match(/^\.mds-table__sort\s*\{([^}]*)\}/m);
    const thRule = source.match(/^\.mds-table__th\s*\{([^}]*)\}/m);

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

/**
 * JR4 — WHICH tables may collapse to cards. This is the half of the collapse that lives in script, and
 * it is the only half any runtime in this repo can observe: happy-dom lays nothing out, so a container
 * query is invisible here by construction (the threshold itself is pinned as source text below).
 *
 * The gate exists because the two kinds of table overlap in width and cannot be told apart by one: a
 * chart's paired two-column table sits in a ~300px card at EVERY viewport, and a full-width page on a
 * phone is a ~343px container. Column count is what separates them.
 */
describe('MdsDataTable — which tables are eligible to collapse', () => {
    const stackable = (wrapper: ReturnType<typeof mount>): boolean =>
        wrapper.find('.mds-table__frame').classes().includes('mds-table__frame--stackable');

    it('gates a two-column table out — it is already a key/value list', () => {
        const wrapper = mount(DataTable, { props: { columns, rows, caption: 'Responses by channel' } });

        expect(stackable(wrapper)).toBe(false);

        wrapper.unmount();
    });

    it('counts the row-actions cell, so two columns plus actions are in', () => {
        // Not a technicality: an action cluster is real width, and `columnCount` has always counted it.
        const wrapper = mount(DataTable, {
            props: { columns, rows, caption: 'Workspaces' },
            slots: { 'row-actions': '<button type="button">Suspend</button>' },
        });

        expect(stackable(wrapper)).toBe(true);

        wrapper.unmount();
    });

    it('gates a three-column table in', () => {
        const wrapper = mount(DataTable, {
            props: {
                columns: [...columns, { key: 'source', header: 'Channel' }],
                rows,
                caption: 'Recent responses',
            },
        });

        expect(stackable(wrapper)).toBe(true);

        wrapper.unmount();
    });

    it('re-evaluates when the column set changes', async () => {
        // The submissions inbox builds five or six columns depending on whether it is scoped to one form,
        // so the gate has to be reactive rather than read once at mount.
        const wrapper = mount(DataTable, { props: { columns, rows, caption: 'Responses' } });

        expect(stackable(wrapper)).toBe(false);

        await wrapper.setProps({ columns: [...columns, { key: 'source', header: 'Channel' }] });

        expect(stackable(wrapper)).toBe(true);

        wrapper.unmount();
    });
});

/**
 * JR4 — the collapse threshold, asserted as SOURCE TEXT for the same reason the JR2 guard above is:
 * nothing in this repo can execute a container query. happy-dom computes no layout; Storybook's axe run
 * renders one viewport; and the e2e horizontal-overflow assertion reads a document width that
 * `.app-shell { overflow-x: clip }` pins flat. What these cases protect is the REASONING — a later
 * edit that reaches for a viewport media query, or for `px`, is re-introducing a defect that was
 * measured once and would be invisible on the way back in.
 */
describe('MdsDataTable — the collapse threshold', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/DataTable/DataTable.vue'),
        'utf8',
    );

    it('collapses on the CONTAINER, never on the viewport', () => {
        expect(source).toMatch(/@container \(max-width:/);
        expect(
            source,
            'a viewport media query cannot see the sidebar inversion — the content box is 896px at a ' +
                '1024px viewport and 721px at 1025px, so a max-width media query fires its densest ' +
                'layout in the narrowest box',
            // Anchored: the block comment in the component NAMES the rule it replaced, and prose about a
            // defect is not the defect. An at-rule sits at column 0.
        ).not.toMatch(/^@media \(max-width: 480px\)/m);
    });

    it('states the threshold in em, so it is correct at all three type scales', () => {
        // A px threshold is right at exactly one setting of §2.9's font-size axis; JR3 shipped that and
        // had to fix it. A container query's em resolves against the container's own font size.
        expect(source).toMatch(/@container \(max-width:\s*\d+em\)/);
    });

    it('establishes the query container on the frame, not on the scroll wrapper', () => {
        // A container query never matches its own container, and the collapse must set `overflow-x` ON
        // the scroll wrapper — put `container-type` there and that declaration becomes unreachable.
        expect(source).toMatch(/\.mds-table__frame\s*\{[^}]*container-type:\s*inline-size/);
    });

    it('keeps the grid track floor guarded, which no runtime gate can check', () => {
        // A bare `minmax(20em, 1fr)` overruns a 343px container; `.app-shell` is `overflow-x: clip`, so
        // the overrun is clipped rather than scrolled and the e2e assertion never sees it.
        expect(source).toMatch(/minmax\(min\(100%, 20em\), 1fr\)/);
    });
});

/**
 * M20 — DSR §4.4 on the stacked sort chip, which is the only sort affordance that exists below the
 * collapse threshold.
 *
 * ⚠️ THE REASON THIS IS A GATE AND NOT A SCREENSHOT: axe passes a 32px control and always will. WCAG 2.2
 * SC 2.5.8's floor is 24×24, so a 32px chip clears the automated bar comfortably — what it breaches is
 * §4.4's stricter 44×44, which no scanner in this repository implements. The chip shipped at 32px behind
 * a fully green gate stack for as long as the stacked layout has existed.
 *
 * Source text, for the reasons the two describes above already give: happy-dom computes no styles, and
 * the rules live inside a container query the unit environment never evaluates.
 */
describe('MdsDataTable — the stacked sort chip must be a 44px touch target', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/DataTable/DataTable.vue'),
        'utf8',
    );

    // Anchored to column 0 for the reason the type-treatment guard above spells out: everything inside an
    // at-rule is indented, and an unanchored pattern would happily match a compound stacked selector.
    const chipRule = /^\.mds-table__sortchip\s*\{([^}]*)\}/m.exec(source)?.[1] ?? '';
    const overlayRule = /^\.mds-table__sortchip::before\s*\{([^}]*)\}/m.exec(source)?.[1] ?? '';
    const barRule = /^\.mds-table__sortbar\s*\{([^}]*)\}/m.exec(source)?.[1] ?? '';

    it('expands the HIT AREA rather than inflating the chip, per §4.4 bullet two', () => {
        expect(overlayRule, '.mds-table__sortchip::before not found — was the hit area removed?').not.toBe('');
        expect(overlayRule).toMatch(/min-width:\s*44px/);
        expect(overlayRule).toMatch(/min-height:\s*44px/);
        // The chip stays visually small; that is the whole point of expanding rather than inflating.
        expect(chipRule).toMatch(/min-height:\s*32px/);
    });

    it('gives the overlay a containing block, or the 44px target lands somewhere else entirely', () => {
        // An absolutely positioned child resolves against the nearest POSITIONED ancestor. This file's own
        // `.mds-table__scroll` docblock calls the same omission "a latent bug in this component".
        expect(chipRule).toContain('position: relative');
        expect(overlayRule).toContain('position: absolute');
    });

    it('keeps the overlay inside the chip horizontally, so a hit area cannot become page overflow', () => {
        // The chip carries its own ≥44px width, so the overlay's `width: 100%` is never the smaller of the
        // two and cannot extend past the chip. `MdsChecklist` documents what the alternative costs: a 10px
        // `scrollWidth` excess that only its container's padding absorbs — and `.mds-table__frame` has no
        // padding. The +2px is the 1px border on each side, which `box-sizing: border-box` folds inward.
        expect(chipRule).toMatch(/min-width:\s*calc\(44px \+ 2px\)/);
        expect(overlayRule).toMatch(/width:\s*100%/);
    });

    /**
     * ⛔ THE PAIR THAT MUST MOVE TOGETHER. §4.4's last bullet asks for 8px between adjacent HIT AREAS, not
     * between visual boxes. A 44px target on a 32px chip overhangs 6px above and below, so two wrapped
     * rows of chips at a uniform 8px gap would overlap by 4px of invisible target — a mis-tap nothing in
     * the gate stack can see. Anyone who "tidies" the two gaps back into one `gap` re-opens it silently.
     */
    it('separates WRAPPED rows of chips by 8px of clear space between their hit areas, not their boxes', () => {
        expect(barRule).toMatch(/row-gap:\s*var\(--mds-space-5\)/); // 20px = 8 required + 2 x 6 overhang
        expect(barRule).toMatch(/column-gap:\s*var\(--mds-space-2\)/); // 8px; no horizontal overhang exists
        expect(barRule, 'a single `gap` cannot express two different separations').not.toMatch(/^\s*gap:/m);
        expect(barRule).toContain('flex-wrap: wrap');
    });

    it('still renders only below the collapse threshold, so none of this costs the desktop layout', () => {
        expect(barRule).toContain('display: none');
        expect(source).toMatch(/\.mds-table__frame--stackable \.mds-table__sortbar \{\s*display: flex;/);
    });
});
