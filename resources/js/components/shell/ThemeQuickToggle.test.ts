import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * J8 — the top bar's theme toggle, and the three states that keep it inside its own box.
 *
 * ── WHY SOURCE TEXT, AND WHY THE NUMBERS ARE COMPARED RATHER THAN PINNED ─────────────────────────────────
 * `MdsSegmentedControl` is `inline-flex` with no wrap and no overflow handling (exceptions-log #13 cost 5),
 * and this instance is the ONLY child of `.topnav__right` that declares `min-width: 0` — so it absorbs the
 * whole of the bar's squeeze and its labels paint out across the Feedback trigger. Nothing in this repo can
 * execute that: happy-dom lays nothing out, the e2e overflow assertion reads `documentElement.scrollWidth`
 * which `.app-shell { overflow-x: clip }` pins flat, and axe has no rule for it.
 *
 * So these assertions compare the breakpoints against the widths where the spill was MEASURED on the
 * running dashboard, rather than pinning the literals. A legitimate re-tune of a threshold stays green; a
 * threshold moved to where the control is known to spill goes red. Pinning `899` would have inverted that.
 *
 *   icon-only content is 139px, and it was measured to spill at:  760px (standard) · 800px (large) ·
 *   834px (extra_large) — fitting at 800 / 834 / 860 respectively.
 */
describe('ThemeQuickToggle — the three states of the top bar toggle', () => {
    const source = readFileSync(
        join(process.cwd(), 'resources/js/components/shell/ThemeQuickToggle.vue'),
        'utf8',
    );

    /** The scoped stylesheet with comments stripped, so prose can never satisfy an assertion. */
    const css = source
        .slice(source.indexOf('<style'), source.lastIndexOf('</style>'))
        .replace(/\/\*[\s\S]*?\*\//g, '')
        // `:deep(X)` is Vue's scoped-CSS escape hatch. Unwrap it so the selectors below read as plain
        // CSS — otherwise the `)` sits between the selector and its `{` and every match here returns ''.
        .replace(/:deep\(([^)]*)\)/g, '$1');

    /** Every `@media (max-width: N)` block, paired with its own brace-matched body. */
    const mediaBlocks = (): Array<{ max: number; body: string }> => {
        const out: Array<{ max: number; body: string }> = [];
        const opener = /@media\s*\(max-width:\s*(\d+)px\)\s*\{/g;
        let match: RegExpExecArray | null;

        while ((match = opener.exec(css)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;

            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }

            out.push({ max: Number(match[1]), body: css.slice(opener.lastIndex, i - 1) });
        }

        return out;
    };

    const blocks = mediaBlocks();
    const collapsing = blocks.filter((b) => b.body.includes('.mds-segmented__seg > span'));
    const hiding = blocks.filter((b) => /\.theme-quick\s*\{[^}]*display:\s*none/.test(b.body));

    it('collapses the labels at two different widths, because the type scale is what runs them out of room', () => {
        // One threshold would rebuild the very blindness this increment removed: the labels are ~214px at
        // the default scale and ~236px at extra_large, so they stop fitting ~100px apart.
        expect(collapsing).toHaveLength(2);
        expect(new Set(collapsing.map((b) => b.max)).size).toBe(2);
    });

    it('hides the label VISUALLY and never with display:none — it is the only accessible name there is', () => {
        // `MdsIcon` is `aria-hidden` unless given a `label`, and this control passes none. A `display: none`
        // span would therefore leave three radios with no accessible name at all, which is a strictly worse
        // outcome than the overlap being fixed.
        expect(collapsing.length).toBeGreaterThan(0);

        for (const block of collapsing) {
            const rule = block.body.match(/\.mds-segmented__seg > span\s*\{([^}]*)\}/)?.[1] ?? '';
            expect(rule, `@media max-width ${block.max}`).toMatch(/position:\s*absolute/);
            expect(rule, `@media max-width ${block.max}`).toMatch(/clip:\s*rect\(0 0 0 0\)/);
            expect(rule, `@media max-width ${block.max}`).not.toMatch(/display:\s*none/);
        }
    });

    it('gives every collapsed segment the 44x44 hit area §4.4 asks for', () => {
        // With the word gone the segment is a bare 16px glyph in ~8px of padding. `.topnav__search-compact`
        // solves the identical problem in this same bar the same way.
        for (const block of collapsing) {
            const rule = block.body.match(/\.mds-segmented__seg\s*\{([^}]*)\}/)?.[1] ?? '';
            expect(rule, `@media max-width ${block.max}`).toMatch(/min-width:\s*44px/);
            expect(rule, `@media max-width ${block.max}`).toMatch(/min-height:\s*44px/);
        }
    });

    it('stops rendering the control ABOVE the widths where icon-only was measured to spill', () => {
        // Collapsing is not sufficient on its own — 139px of icons still does not fit at the bottom of the
        // band, and there is no further width to take that is not the search field.
        expect(hiding).toHaveLength(2);

        const enlarged = hiding.find((b) => b.body.includes('html[data-font-size]'));
        const standard = hiding.find((b) => !b.body.includes('html[data-font-size]'));

        expect(enlarged, 'a hide rule scoped to the enlarged scales').toBeDefined();
        expect(standard, 'a hide rule for the default scale').toBeDefined();

        // extra_large icon-only spills at 834 and fits at 860; the default scale spills at 760, fits at 800.
        expect(enlarged!.max).toBeGreaterThanOrEqual(860);
        expect(standard!.max).toBeGreaterThanOrEqual(760);

        // The enlarged scales run out of room first, so their threshold must be the wider of the two.
        expect(enlarged!.max).toBeGreaterThan(standard!.max);
    });

    it('selects the enlarged scales by the bare attribute, which is the FontSizeScale contract', () => {
        // `FontSizeScale::attributeValue()` emits NO attribute for Standard, so `html[data-font-size]` means
        // exactly "large or extra_large" and keeps meaning it if a fourth step is ever added. A hard-coded
        // list of the two values would silently exclude the new one.
        expect(css).toContain('html[data-font-size]');
        expect(css).not.toMatch(/data-font-size\s*=\s*['"]/);
    });

    it('collapses before it hides, on each type scale separately', () => {
        // Hiding a control whose labels still fit would be a regression dressed as a fix, and the two
        // scales cross over — the enlarged hide (899) and the default collapse (899) coincide — so this
        // has to be compared WITHIN a scale. Comparing the raw maxima across all four blocks would fail
        // on a correct file, which is how an over-strong assertion becomes the next defect.
        const of = (list: typeof blocks, enlarged: boolean) =>
            list.find((b) => b.body.includes('html[data-font-size]') === enlarged)!.max;

        expect(of(hiding, true), 'enlarged: hide must be narrower than collapse').toBeLessThan(
            of(collapsing, true),
        );
        expect(of(hiding, false), 'default: hide must be narrower than collapse').toBeLessThan(
            of(collapsing, false),
        );
    });

    it('never touches the control at a desktop width', () => {
        // The control that must survive: "fixed by collapsing it everywhere" would satisfy every assertion
        // above. 1024 is §6's tablet boundary and the widest threshold this file may carry — past it the
        // labelled state is the only state.
        for (const block of blocks) {
            expect(block.max, `@media max-width ${block.max} reaches into desktop`).toBeLessThanOrEqual(1024);
        }
    });
});
