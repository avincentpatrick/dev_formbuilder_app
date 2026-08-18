import { expect, test } from '@playwright/test';
import { assertClean, forcePersonalization, forceTheme } from './support/axe';
import { openBuilder, showBuilderPane } from './support/navigate';

/**
 * Increment G11 — the personalization axes (design-system-reference.md §2.9).
 *
 * Two things need covering, and they need covering differently:
 *
 *  · ACCENT is a colour surface, so it needs axe in both themes. Until G11 nothing in this suite had
 *    ever set data-accent, which is exactly why the shipped C2 accent stub could carry a 1.74:1
 *    action-primary-fg on the dark ground undetected. Loop A closes that hole permanently.
 *
 *  · TEXT SIZE and the DYSLEXIA FACE are reflow surfaces — they change glyph widths and line boxes,
 *    so what matters is that nothing overflows or collides.
 *
 * Deliberately NOT a full matrix. Crossing font-size × accent × dyslexia × theme × viewport would be
 * 72 runs on `workers: 1`. Instead: extra_large strictly dominates large for reflow, and dark+teal is
 * the only genuinely new colour pairing — so Loop B runs the maximum-stress combination only, and the
 * existing responsive-axe baseline (untouched) keeps covering the un-personalized default.
 *
 * Total: 6 + 12 = 18 runs across the three viewport projects.
 */

// ── Loop A: accent contrast ──────────────────────────────────────────────────────────────────────
// /forms is the densest colour surface in the app, which is why the accent loop lives here: it
// exercises the accent's action/focus tokens against the most backgrounds per page load.
//
// ⚠️ WHAT IS ON THIS PAGE CHANGED IN JR3 AND THIS COMMENT WAS STALE. It used to read "primary CTA,
// DataTable, status badges, pagination" — `/forms` now renders a CARD GRID by default and has never had
// pagination at all. The surfaces it actually exercises today are the primary CTA, the segmented view
// toggle, the counted facet chips, six `MdsCard`s with per-form identity hues, the status badges and the
// capacity meter. The table is still scanned, but only via the explicit `?view=table` entry in
// `responsive-axe.spec.ts` — this loop no longer sees one.
for (const theme of ['light', 'dark'] as const) {
    test(`Teal accent on Forms (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await forceTheme(page, theme);
        await forcePersonalization(page, { accent: 'teal' });
        await assertClean(page, `Forms (teal, ${theme})`);
    });
}

// ── Loop B: maximum reflow ───────────────────────────────────────────────────────────────────────
// extra_large text + the wider OpenDyslexic body face + teal, on the dark ground. If any composed page
// is going to overflow or lose contrast under personalization, it is this combination.
const reflowPages = [
    // Settings owns the four Appearance rows themselves — the widest of the new controls lives here.
    { name: 'Settings', path: '/settings' },
    { name: 'Forms', path: '/forms' },
    { name: 'Submissions', path: '/submissions' },
];

for (const target of reflowPages) {
    test(`${target.name} at extra_large + dyslexia font + teal — accessible & no horizontal overflow`, async ({
        page,
    }) => {
        await page.goto(target.path, { waitUntil: 'networkidle' });
        await forceTheme(page, 'dark');
        await forcePersonalization(page, {
            accent: 'teal',
            fontSize: 'extra_large',
            dyslexia: true,
        });
        await assertClean(page, `${target.name} (max personalization)`);
    });
}

// The builder is the densest layout in the app (three panes above 60em of its own container, one pane and a
// switcher below it), so it is the most likely place for a 25% text growth to collide. Scanned at maximum
// stress, and since JR5 across all three panes rather than only the one the whole-page scan happens to see.
//
// ⚠️ THIS TEST IS THE ONE THAT MAKES THE `em` THRESHOLD OBSERVABLE, AND IT IS THE LEAST OBVIOUS CONSEQUENCE
// OF CHOOSING `em` OVER `px`. A container query's font-relative units resolve against the container's own
// font size, so 60em is 960 / 1080 / 1200px across §2.9's three scales — which means that the moment
// `forcePersonalization` sets `extra_large`, THE BUILDER GOES COMPACT AT THE 1440 DESKTOP PROJECT TOO. That
// is the design working (the 260/340 pane columns are px literals that do not grow with the type), and it
// is why `showBuilderPane` asks the page what is on screen instead of reading `info.project.name`.
//
// ⚠️ AND THE PANE SWEEP MUST COME *AFTER* `forcePersonalization`. Before it, at the desktop project, the
// switcher is not on screen, the helper returns false, and the sweep silently does nothing at exactly the
// combination it exists to stress.
test('Builder at extra_large + dyslexia font + teal — accessible & no horizontal overflow', async ({
    page,
}) => {
    await openBuilder(page, 'Community Health Survey');
    await showBuilderPane(page, 'settings');

    await forceTheme(page, 'dark');
    await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });
    await assertClean(page, 'Builder (max personalization)');

    // The `overflow-x: clip` blind spot, third instance (after MdsDataTable and the notification panel).
    // `MdsSegmentedControl` is `inline-flex` with `white-space: nowrap` and no wrap and no overflow
    // handling, so a switcher that does not fit spills OUT of its bar while the document width never
    // moves — invisible to `assertClean` by construction. 375px × extra_large × OpenDyslexic is the exact
    // combination the width arithmetic in Builder.vue is about, so it is measured here rather than assumed.
    const strip = page.locator('.builder__pane-switch');
    if (await strip.isVisible()) {
        const spill = await strip.evaluate((el) => el.scrollWidth - el.clientWidth);
        expect(spill, 'the pane switcher overflows its bar under maximum personalization').toBeLessThanOrEqual(1);
    }

    if (await showBuilderPane(page, 'fields')) {
        await assertClean(page, 'Builder (max personalization) — Add');
        await showBuilderPane(page, 'canvas');
        await assertClean(page, 'Builder (max personalization) — Form');
    }
});
