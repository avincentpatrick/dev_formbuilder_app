import { expect, type Locator, type Page } from '@playwright/test';

/**
 * One form's entry on `/forms`, in WHATEVER view the page is currently rendering (JR3).
 *
 * ── WHY THIS EXISTS, AND WHY IT IS THE SAME LESSON AS `openBuilder` ─────────────────────────────────────
 * Seven tests in `responsive-axe.spec.ts` reached a form by `page.locator('tr').filter({ hasText })` and
 * then clicked a row action inside it. JR3 made the CARD GRID the default view, so there are no `tr`
 * elements on that page unless `?view=table` is asked for — every one of those seven would have matched
 * zero elements at all three viewports, and none of it is visible on this host because the e2e suite
 * cannot run here. Exactly the shape of J2b's 81 failures: one deliberate page change, spelled seven
 * times in a place the person making the change was not looking.
 *
 * ⚠️ THE SELECTOR IS DELIBERATELY VIEW-AGNOSTIC IN BOTH DIRECTIONS, not merely switched to the card. It
 * matches the table row AND the card's `<li data-form-entry>`, so it keeps working if the default flips
 * back, if a test asks for `?view=table` explicitly, or if a third view is ever added — the caller says
 * which form it wants and never which layout it expects.
 *
 * ⚠️ `tr` cannot be `getByRole('row')` here, and that is not an oversight: `MdsDataTable` drops table
 * ARIA for its card-per-row layout, so the role query finds nothing wherever that layout is in force —
 * since JR4 that is any width at which the table's CONTAINER is under 56em, i.e. the 834px project as
 * well as 375px. The tag selector is the thing that survives every one of this page's layout switches.
 *
 * ⚠️ AND IT FILTERS ON THE TITLE LINK, NOT ON `hasText`, WHICH THE OLD CALL SITES USED. A card renders
 * the form's DESCRIPTION and the table row never did, so a substring text filter would newly match any
 * card whose description happens to contain another form's name — resolving to two elements and failing
 * strict mode, at a viewport nobody can reproduce locally. Matching the exact accessible name of the
 * title link is precise in both views and cannot be widened by anything the card starts rendering later.
 */
export function formEntry(page: Page, formTitle: string): Locator {
    return page
        .locator('tr, [data-form-entry]')
        .filter({ has: page.getByRole('link', { name: formTitle, exact: true }) });
}

/**
 * Open a form's BUILDER from the forms list (Increment J2b).
 *
 * ── WHY THIS IS A SHARED HELPER RATHER THAN SIX COPIES ──────────────────────────────────────────────────
 * It was six copies, and CI is what proved that was a mistake. J2b repointed `forms/Index.vue`'s row title
 * at the new form HUB — a deliberate fix, because the title used to link to the builder and only
 * `v-if="row.can.edit"`, so a non-editor got inert text. Every e2e spec that reached the builder by clicking
 * that title then landed on `/forms/{uuid}` and timed out waiting for the builder glob: **81 failures across
 * four spec files**, all one edit, spelled six times. That is J1e's "the second caller is the one that lies"
 * finding arriving from the other direction — the copies did not disagree with each other, they all agreed
 * with a page that had moved.
 *
 * ⚠️ AND DO NOT WRITE THAT GLOB PATTERN INTO A DOCBLOCK. The first draft of this comment spelled it out
 * literally, and the two asterisks followed by a slash CLOSE the block comment — the file then fails to
 * parse with "Missing semicolon" pointing at an apostrophe four lines further down, i.e. nowhere near the
 * cause. Same family as the bare tag literal that once broke the Storybook build alone. Name it in prose.
 *
 * ⚠️ AND `/forms` HAS NO BUILDER ROW-ACTION AT ALL, which is the fact that decides the route taken here.
 * The row's `edit` icon is **Rename form** (it opens a modal); the other actions are analytics, encode,
 * history, template, scope, publish and archive. So the title link was the ONLY way into the builder from
 * the list, and after J2b the way in is the HUB'S BUILDER TAB. Two clicks rather than one, and that is the
 * product's actual shape now rather than a test convenience: the hub is a form's entry point.
 *
 * The tab strip is located by its accessible name, which `MdsTabNav` sets to the FORM TITLE (not "Tabs") —
 * axe's `landmark-unique` distinguishes navigation landmarks by name, and the breadcrumb trail on the same
 * page is named "Breadcrumb", so the two do not collide. Waiting for the hub URL between the clicks is what
 * makes a failure here name the step that broke: without it, a regression in the title link reports as a
 * missing Builder tab.
 */
export async function openBuilder(page: Page, formTitle: string): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: formTitle }).click();

    // Anchored: the hub IS `/forms/{uuid}`, so without the `$` this also matches the builder and the
    // analytics page and the wait would pass for the wrong reason.
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });

    await page.getByRole('navigation', { name: formTitle }).getByRole('link', { name: 'Builder' }).click();
    await page.waitForURL('**/builder', { timeout: 30_000 });
}

/**
 * The builder's three panes, keyed by the pane switcher's own label (JR5).
 *
 * The label is what gets clicked and the selector is what gets awaited, so the two live together — a
 * relabelled segment breaks compilation here rather than timing out in eleven call sites.
 */
const BUILDER_PANES = {
    fields: { label: 'Add', selector: '.builder__pane--left' },
    canvas: { label: 'Form', selector: '.builder__pane--canvas' },
    settings: { label: 'Settings', selector: '.builder__pane--config' },
} as const;

/**
 * Put ONE of the builder's three panes on screen, at whatever width the project is running (JR5).
 *
 * ── WHY EVERY BUILDER SPEC NOW NEEDS THIS ───────────────────────────────────────────────────────────────
 * JR5 replaced the builder's `@media (max-width: 1024px)` linearization — which stacked all three panes
 * into one scrolling column, so everything was on screen and no spec ever had to ask for a pane — with
 * `@container (max-width: 60em)`, below which exactly ONE pane is displayed. Anything a spec reaches for
 * inside the palette, the canvas or the config panel now has to say which pane it wants first.
 *
 * ⚠️ RUNTIME-CONDITIONAL, NEVER `info.project.name`. The threshold is 60em of the BUILDER's own box, and
 * a container query's `em` resolves against the container's own font size — 16/18/20px across §2.9's three
 * scales, so 60em is 960 / 1080 / 1200px. `personalization-axe.spec.ts` therefore reaches the compact
 * layout AT THE 1440 DESKTOP PROJECT the moment it sets `data-font-size="extra_large"`. A helper keyed on
 * the project name would be wrong there, and only there, which is exactly where nobody would look.
 *
 * ⚠️ THE CLICK LANDS ON THE OPTION'S LABEL TEXT, NOT ON THE RADIO. `MdsSegmentedControl` hides its inputs
 * at 1×1px with `clip`, so `.check()` on one is an actionability coin-flip. Clicking the visible label is
 * the pattern `builder-axe.spec.ts` already uses for the Structure/Logic control. The `getByText` is scoped
 * to the switcher and the control's `ariaLabel` deliberately shares no word with any option, so the
 * visually-hidden legend cannot resolve as a second match.
 *
 * Returns FALSE when the switcher is not on screen — i.e. the builder is wide and all three panes are
 * already up — so a caller can skip a per-pane scan that would duplicate the whole-page one:
 *
 *     if (await showBuilderPane(page, 'fields')) { await scan(page, 'compact — Add'); }
 */
export async function showBuilderPane(
    page: Page,
    pane: keyof typeof BUILDER_PANES,
): Promise<boolean> {
    // Hydration settle. `.builder__panes` exists only once Vue has rendered, and this REPLACES the
    // `getByRole('tab').first()` settle three specs used — that locator resolved into ConfigPanel's
    // tablist, which is no longer on screen at a width where the config pane is not the selected one.
    await page.locator('.builder__panes').waitFor({ state: 'visible', timeout: 30_000 });

    const strip = page.locator('.builder__pane-switch');
    if (!(await strip.isVisible())) return false;

    await strip.getByText(BUILDER_PANES[pane].label, { exact: true }).click();
    await expect(page.locator(BUILDER_PANES[pane].selector)).toBeVisible({ timeout: 30_000 });

    return true;
}
