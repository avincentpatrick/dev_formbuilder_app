import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * JR5 — the containing-block guard for this component's two visually-hidden nodes.
 *
 * ── WHY SOURCE TEXT, AND WHY THIS COMPONENT HAD NO TEST AT ALL UNTIL NOW ─────────────────────────────────
 * `MdsSegmentedControl` hides its `<legend>` and its radio `<input>`s with the standard
 * `position: absolute` + `clip: rect(0 0 0 0)` pattern. Absolute positioning resolves against the nearest
 * POSITIONED ancestor, so without `position: relative` on the fieldset those two 1px nodes are laid out
 * against whatever is positioned further up — which means no scroll container between the control and the
 * document can clip them, and a hidden node parked past a viewport edge extends the DOCUMENT's scrollable
 * box.
 *
 * This is the second time the repo has hit it. G11 found it horizontally on `MdsDataTable` (the
 * `extra_large` + dyslexia type scale pushed a hidden "Actions" span past 834px and the page gained 50px
 * of real horizontal scroll). JR5 found it vertically here: at 375px with the builder's config pane
 * selected, this control's hidden legend sat 73px below a workspace that is `height: 100%` from `<body>`
 * down, and the page scrolled behind it.
 *
 * ⚠️ NOTHING IN THIS REPO CAN EXECUTE THE CHECK. happy-dom computes no layout, so a mounted assertion
 * would pass whatever the CSS said; the e2e overflow assertion reads `documentElement.scrollWidth`, which
 * `.app-shell { overflow-x: clip }` pins flat, and no gate reads scrollHeight; axe has no rule for a
 * hidden node extending the page. Source text is therefore the only place this can be held.
 */
describe('MdsSegmentedControl — the visually-hidden nodes stay inside the control', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/SegmentedControl/SegmentedControl.vue'),
        'utf8',
    );

    /** The declaration block of a scoped-CSS rule, by selector. */
    const block = (selector: string): string =>
        source.match(new RegExp(`${selector.replace('.', '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('positions the fieldset, so it is the containing block for both of them', () => {
        expect(block('.mds-segmented')).toMatch(/position:\s*relative/);
    });

    it('still hides the group label and the inputs the accessible way', () => {
        // If either of these ever stops being absolutely positioned the rule above becomes unnecessary
        // rather than wrong — but a `display: none` legend would remove the group's name from the
        // accessibility tree, and a `display: none` input would take the radios out of the tab order and
        // kill the native arrow-key roving this component gets for free.
        for (const selector of ['.mds-segmented__legend', '.mds-segmented__input']) {
            expect(block(selector), selector).toMatch(/position:\s*absolute/);
            expect(block(selector), selector).toMatch(/clip:\s*rect\(0 0 0 0\)/);
            expect(block(selector), selector).not.toMatch(/display:\s*none/);
        }
    });
});
