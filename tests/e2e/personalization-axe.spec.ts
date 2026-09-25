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
    // `MdsSegmentedControl` has no wrap and no overflow handling, so a switcher that does not fit spills
    // OUT of its bar while the document width never moves — invisible to `assertClean` by construction.
    // 375px × extra_large × OpenDyslexic is the exact combination the width arithmetic in Builder.vue is
    // about, so it is measured here rather than assumed.
    //
    // ⚠️ M19 — THIS COMMENT SAID `white-space: nowrap` AND THAT IS NOT IN THE COMPONENT. The only two
    // `white-space: nowrap` declarations in `SegmentedControl.vue` are the sr-only `legend` and `input`,
    // neither of which affects layout. The real construction is `inline-flex` with no `flex-wrap`, a
    // `__seg` carrying neither `min-width: 0` nor `flex-shrink`, and `min-width: 0` on the fieldset —
    // which does not mitigate the spill, it ENABLES it by removing the fieldset's floor. Corrected here
    // and in the backlog row that QUOTES this comment verbatim — the error had propagated by citation,
    // which is how a wrong mechanism comes to look corroborated. ⚠️ `ThemeQuickToggle.vue` was checked
    // and is NOT a third copy: it says "inline-flex with no wrap and no overflow handling", which is
    // exactly right. Two files, not three — counted rather than assumed.
    const strip = page.locator('.builder__pane-switch');
    if (await strip.isVisible()) {
        const spill = await strip.evaluate((el) => el.scrollWidth - el.clientWidth);
        expect(spill, 'the pane switcher overflows its bar under maximum personalization').toBeLessThanOrEqual(1);
    }

    // ⛔ M109 — THE DOCUMENT-LEVEL ASSERTION CANNOT SEE THIS ONE, BY CONSTRUCTION, AND THAT IS WHY THE
    // ORIGINAL M17 ROW WAS FALSIFIED. `.config` is `overflow-y: auto`, which forces `overflow-x` to
    // compute to `auto` too, so the Requiredness control's spill becomes a real horizontal scrollbar
    // INSIDE the pane. `assertNoHorizontalOverflow` deliberately skips any subtree under an
    // `overflow-x: auto|scroll` ancestor and reports it as `absorbed`, never as the cause — the note at
    // `support/axe.ts` already names this exact control. Only an element-level read decides it, which is
    // what D28 asks for. The fix is `flex-wrap: wrap` at each of D28's four stretch-clamped hosts.
    //
    // ⚠️ ASK FOR THE PANE BACK FIRST, AND THE REASON IS THE SAME ONE THE HEADER ABOVE GIVES. The builder
    // is COMPACT at `extra_large` even on the desktop project, so after `forcePersonalization` the config
    // pane is no longer the one on screen — the `showBuilderPane` at the top of this test ran while the
    // layout was still wide and did nothing. `false` here means the layout is wide and all three are up.
    await showBuilderPane(page, 'settings');

    // ⛔ THE GUARD IS ON THE MEASUREMENT, NOT ON THE ELEMENT COUNT, AND THE DIFFERENCE IS NOT ACADEMIC:
    // `.config` is in the DOM even when its pane is display:none, where `scrollWidth - clientWidth` reads
    // 0 and the assertion below would pass over a layout it never looked at. Measured on this test's
    // first run, which is the only reason this is `toBeVisible` and not `toHaveCount(1)`. The Requiredness
    // group is `v-if="!isCalculated"` too, so a calculated auto-selection would hide it the same way.
    const configPane = page.locator('.config');
    await expect(configPane, 'the builder config pane is not on screen, so the measurement would be vacuous').toBeVisible();
    await expect(
        configPane.getByRole('group', { name: 'Requiredness' }),
        'the Requiredness segmented control did not render, so the measurement would be vacuous',
    ).toBeVisible();

    const configSpill = await configPane.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(
        configSpill,
        'the builder config pane scrolls sideways under maximum personalization',
    ).toBeLessThanOrEqual(1);

    if (await showBuilderPane(page, 'fields')) {
        await assertClean(page, 'Builder (max personalization) — Add');
        await showBuilderPane(page, 'canvas');
        await assertClean(page, 'Builder (max personalization) — Form');
    }
});
