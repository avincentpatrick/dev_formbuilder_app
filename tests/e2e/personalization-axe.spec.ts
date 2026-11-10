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
    //
    // ⛔ M218 (`R-20b1fe78`, `R-ffd296b1`): THIS READ USED TO SIT INSIDE `if (await strip.isVisible())`, so on
    // any width where the switcher was off screen it was skipped without a word, and the test passed having
    // measured nothing. It need not be conditional: at `extra_large` the builder is compact at all three
    // widths (the note below says why), so the switcher is always on screen here, and if it is not, that is
    // the failure.
    const strip = page.locator('.builder__pane-switch');
    await expect(strip, 'the pane switcher is not on screen, so the measurement below would be vacuous').toBeVisible();
    const spill = await strip.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(spill, 'the pane switcher overflows its bar under maximum personalization').toBeLessThanOrEqual(1);

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


    // ⛔ THE CENTRE CONTROL, MEASURED FOR THE FIRST TIME IN M118, AND IT IS THE ONE SEGMENTED CONTROL ON THIS
    // PAGE NO GATE COULD SEE. The pane switcher above has had an element-level read since M19 and the config
    // pane since M109, but `.builder__centre-tabs` had neither — and it is the control M118 widened from two
    // segments to three (`Structure · Preview · Logic`). `.builder__pane { overflow: hidden }` CLIPS its
    // spill rather than scrolling it, so `assertNoHorizontalOverflow`'s `.app-shell__content` read is flat
    // over it by construction, and axe has no rule for a clipped label. The identical `inline-flex` with no
    // `flex-wrap` that M19 documented for the pane switcher applies here, one segment wider.
    await showBuilderPane(page, 'canvas');

    const centre = page.locator('.builder__centre-tabs');
    await expect(
        centre,
        'the builder centre control is not on screen, so the measurement would be vacuous',
    ).toBeVisible();
    await expect(
        centre.getByText('Preview', { exact: true }),
        'the third segment did not render, so a spill of zero would prove nothing',
    ).toBeVisible();

    const centreSpill = await centre.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(
        centreSpill,
        'the builder centre control overflows its bar under maximum personalization',
    ).toBeLessThanOrEqual(1);

    if (await showBuilderPane(page, 'fields')) {
        await assertClean(page, 'Builder (max personalization) — Add');
        await showBuilderPane(page, 'canvas');
        await assertClean(page, 'Builder (max personalization) — Form');
    }
});

// ⛔ THE FOURTH ELEMENT-LEVEL SPILL READ ON THIS PAGE, AND IT MEASURES THE WIDEST CONTROL OF THE FOUR.
// The pane switcher has had one since M19, the config pane since M109 and the centre control since M118 —
// each added because `.builder__pane { overflow: hidden }` CLIPS a spill instead of scrolling it, so
// `assertNoHorizontalOverflow`'s `.app-shell__content` read is flat over it by construction and axe has no
// rule for a clipped label. The section strip is the same `inline-flex` with no wrap, carrying SECTION
// NAMES rather than one-word labels, so it is the likeliest of the four to overflow and was the first to do
// so: this case was written before the `flex-wrap` host guard existed and went red on the real defect.
//
// ⛔ UNTIL M218 A SPILL READ ALONE COULD NOT FAIL HERE, WHICH WAS MEASURED RATHER THAN REASONED ABOUT. This
// case opened `Logic Notices Demo`, which carries seven sections, but relevance gates four of them at the
// empty answer state, so the strip rendered exactly three short segments occupying 335px of a 335px bar at
// 375px with `extra_large` and OpenDyslexic. `Community Health Survey` projects to two. No seeded form gave
// a strip wide enough to overflow, so the spill read was green by construction. `R-ffd296b1` added
// `Section Strip Demo` (`E2eSeeder`): seven always-visible sections, the most the strip draws as segments,
// each carrying one long word. A segment shrinks to its longest word and no further, so the words are what
// overflow the bar unless the control wraps, at all three widths. The segment count is pinned below, so a
// fixture that stopped projecting all seven would fail here rather than quietly turn the read back into a
// decoration, and the case proves on every run that the fixture can still make the read fail.
//
// ✅ AND THE STRUCTURAL HALF STAYS, BECAUSE IT ANSWERS A DIFFERENT QUESTION. Section names are author-controlled and
// unbounded, so the strip's protection cannot be "the fixtures happen to be short"; it is `D28`'s measured
// affordance, `flex-wrap` on the hosted control, and that is asserted directly. Written before the guard
// existed and RED on the first run, reporting `nowrap`. The spill read is kept beside it because the two
// answer different questions: one that the affordance is present, the other that it is sufficient.
test('Builder preview strip at extra_large + dyslexia font + teal — no horizontal overflow', async ({
    page,
}) => {
    await openBuilder(page, 'Section Strip Demo');
    await forceTheme(page, 'dark');
    await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });

    // ⚠️ AFTER `forcePersonalization`, NEVER BEFORE. At `extra_large` the builder goes compact even at the
    // 1440 desktop project, because its 60em container threshold resolves against the personalised type
    // scale — so a pane selected earlier is not the pane on screen now.
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Preview', { exact: true }).click();
    await expect(page.locator('[data-builder-preview]')).toBeVisible({ timeout: 15_000 });

    const strip = page.locator('[data-preview-strip]');
    await expect(
        strip,
        'the section strip is not on screen, so the measurement below would be vacuous',
    ).toBeVisible({ timeout: 15_000 });

    // ⛔ IT MUST HAVE RENDERED AS SEGMENTS, not as the select it degrades to above the ceiling.
    // A `<select>` cannot overflow its bar, so a spill of zero measured on one is not a weaker
    // result — it is no result at all, and it would look identical to a pass.
    // M218: pinned at the fixture's seven, every section a step, rather than "more than one".
    const segments = await strip.locator('input[type="radio"]').count();
    expect(
        segments,
        'the strip did not render all seven of Section Strip Demo\'s sections as segments, so the reads below are not reading the fixture they were written for',
    ).toBe(7);

    // ⛔ M218 (`R-ffd296b1`): THE FIXTURE MUST BE ABLE TO TURN THE SPILL READ RED, AND THAT IS MEASURED HERE,
    // AT EVERY WIDTH AND ON EVERY RUN, rather than trusted from the day the names were chosen. The wrap guard
    // is taken off for one read, the strip must spill, and the guard is back before the real reads below.
    const unguarded = await strip.locator('fieldset').evaluate((el) => {
        const host = el as HTMLElement;
        host.style.flexWrap = 'nowrap';
        const bar = host.closest('[data-preview-strip]') as HTMLElement;
        const out = bar.scrollWidth - bar.clientWidth;
        host.style.removeProperty('flex-wrap');

        return out;
    });
    expect(
        unguarded,
        'without its wrap guard the strip still fits, so the fixture cannot make the spill read below fail',
    ).toBeGreaterThan(1);

    const wrap = await strip.locator('fieldset').evaluate((el) => getComputedStyle(el).flexWrap);
    expect(
        wrap,
        'the hosted segmented control cannot wrap, so a long section name will spill a bar that CLIPS it',
    ).toBe('wrap');

    const spill = await strip.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(
        spill,
        'the preview section strip overflows its bar under maximum personalization',
    ).toBeLessThanOrEqual(1);

    await assertClean(page, 'Builder preview strip (max personalization)');
});

// M218 (`R-ffd296b1`): THE BRANCH ONLY VITEST HAD EVER DRAWN. Above `PREVIEW_STRIP_MAX_SEGMENTS` the strip
// stops being a segmented control and becomes a select, and no seeded form had that many steps until
// `Many Sections Demo` (`E2eSeeder`, eight sections, none conditional). It gets the same treatment as the
// segments above: maximum personalization, its bar read, and an axe scan.
test('Builder preview strip above seven sections is a select — accessible & no horizontal overflow', async ({
    page,
}) => {
    await openBuilder(page, 'Many Sections Demo');
    await forceTheme(page, 'dark');
    await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });

    // After `forcePersonalization`, for the reason the case above gives.
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Preview', { exact: true }).click();
    await expect(page.locator('[data-builder-preview]')).toBeVisible({ timeout: 15_000 });

    const strip = page.locator('[data-preview-strip]');
    const select = strip.getByRole('combobox', { name: 'Jump to section' });
    await expect(select, 'eight sections did not render as the select the strip falls back to').toBeVisible({
        timeout: 15_000,
    });
    await expect(strip.locator('input[type="radio"]')).toHaveCount(0);
    // Every step is an option, so the fixture is above the ceiling rather than at it.
    await expect(select.locator('option')).toHaveCount(8);

    const spill = await strip.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(spill, 'the section select overflows its bar under maximum personalization').toBeLessThanOrEqual(1);

    await assertClean(page, 'Builder preview strip as a select (max personalization)');
});
