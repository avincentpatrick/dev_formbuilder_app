import { expect, test } from '@playwright/test';
import { settlePaint } from './support/axe';
import { openBuilder } from './support/navigate';

/**
 * Increment JR4 — the two properties this increment adds, neither of which any existing gate can see.
 * (JR5 added a third at the bottom of this file, for the same structural reason: the builder's compact
 * layout is a container query, and a container query is invisible to every other gate in this repo.)
 *
 * ⚠️ `assertClean` CANNOT STAND IN FOR EITHER OF THESE, AND THAT IS STRUCTURAL RATHER THAN AN OVERSIGHT.
 * `.app-shell` is `overflow-x: clip` (`AppLayout.vue`), so a table wider than its region is CLIPPED, not
 * scrolled: `document.documentElement.scrollWidth` never grows and the shared overflow assertion stays
 * green over a sideways-scrolling strip. The same blindness is why `search-nav.spec.ts` measures bounding
 * boxes rather than document width. And the gutter the width class closes is exactly 0 at 1440 — the
 * widest project in the matrix — which is why nobody's screen ever showed it.
 *
 * So this file measures ELEMENTS: the scroll wrapper's own overflow, and the content column's own width.
 */

// Every one of these mounts an unconditional `MdsDataTable` with 4+ columns (5+ counting the action cell),
// so `--stackable` is present on all of them and the E2eSeeder gives each one rows. `/integrations` is
// deliberately absent: it renders one table PER connection inside a `v-for`, so its table count depends on
// fixture data in a way that would make the anti-vacuity check below fragile rather than useful.
const listPages = [
    { name: 'Submissions', path: '/submissions' },
    { name: 'Members', path: '/members' },
    { name: 'Webhooks', path: '/webhooks' },
    { name: 'Audit log', path: '/audit-log' },
    { name: 'Feedback', path: '/feedback' },
    { name: 'Forms (table view)', path: '/forms?view=table' },
];

for (const p of listPages) {
    test(`${p.name} — no dense table scrolls sideways below the collapse threshold`, async ({ page }, info) => {
        test.skip(
            info.project.name === 'desktop',
            'Above 56em of container a dense table is DESIGNED to scroll — the seven-column forms table ' +
                'needs ~1136px and gets it at 1440. What this asserts is the band BELOW the threshold, ' +
                'which is the 375 and 834 projects.',
        );

        await page.goto(p.path, { waitUntil: 'networkidle' });
        await settlePaint(page);

        const scrollers = page.locator('.mds-table__frame--stackable .mds-table__scroll');
        const count = await scrollers.count();

        // Not vacuous: a renamed class, or a page that stopped rendering a table, must fail here loudly
        // rather than pass over zero elements.
        expect(count, `${p.path} rendered no stackable table`).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            const overflow = await scrollers.nth(i).evaluate((el) => el.scrollWidth - el.clientWidth);

            expect(overflow, `table ${i} on ${p.path} still scrolls sideways at this viewport`).toBeLessThanOrEqual(1);
        }
    });
}

test('the wide column fills a 1600px window, and a form page still does not', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

    // 1600 rather than the project's 1440, deliberately: at 1440 the 1200px cap is not even reached, so the
    // change under test is invisible. This is the smallest viewport at which it is observable at all.
    await page.setViewportSize({ width: 1600, height: 900 });

    await page.goto('/submissions', { waitUntil: 'networkidle' });
    const list = await page.locator('.app-shell__inner').boundingBox();

    // Region = 1600 − 240 (sidebar) − a scrollbar, and the 1600px cap does not bind: the column fills it.
    expect(list!.width, 'a list page should use the whole 1600px window').toBeGreaterThan(1300);

    await page.goto('/settings', { waitUntil: 'networkidle' });
    const form = await page.locator('.app-shell__inner').boundingBox();

    // Unchanged, and that is the point of an opt-in list: 1600px of 640px-wide settings cards would be
    // 900px of dead canvas beside a column of controls.
    expect(Math.round(form!.width), 'a form page must keep its measure').toBe(1200);
});

// ── JR5 — THE BUILDER'S COMPACT LAYOUT ──────────────────────────────────────────────────────────────────
// Same blindness, second surface, which is why this case lives here rather than in a new file: the header
// above describes a general problem (`.app-shell` is `overflow-x: clip`, so `assertClean` reads a document
// width that can never grow), not a JR4-specific one.
//
// What no other gate in this repo can see: below `@container (max-width: 60em)` of the builder's own box
// exactly ONE pane is displayed and the other two are `display: none`. A broken `--show-*` mapping — a typo
// in one CSS selector — would hide all three and leave a blank workspace that axe reports as perfectly
// clean, `assertClean` reports as non-overflowing, and every source-text assertion in
// `builder-layout.test.ts` still passes. Counting the panes that actually have a box is the only thing
// that catches it.
test('the builder shows exactly one pane below its threshold, and three above', async ({ page }, info) => {
    await openBuilder(page, 'Community Health Survey');
    await page.locator('.builder__panes').waitFor({ state: 'visible', timeout: 30_000 });
    await settlePaint(page);

    const panes = page.locator('.builder__pane');

    // `display: none`, never `v-if` — three gates in this suite need these attached at every width.
    expect(await panes.count(), 'the three panes must stay in the DOM at every width').toBe(3);

    const shown = await panes.evaluateAll(
        (els) => els.filter((el) => (el as HTMLElement).offsetParent !== null).length,
    );
    const compact = await page.locator('.builder__pane-switch').isVisible();

    expect(shown, compact ? 'compact: exactly one pane' : 'wide: all three panes').toBe(compact ? 1 : 3);

    // Anti-vacuity in BOTH directions, and it is what pins the threshold itself. The builder's box is
    // `viewport − sidebar`: 375 − 0 (drawer) and 834 − 64 are far below 60em (960px), while 1440 − 240 is
    // 1200px and above it. A threshold that drifted either way would leave a project on the wrong side.
    expect(compact, `${info.project.name} landed on the wrong side of 60em`).toBe(
        info.project.name !== 'desktop',
    );
});
