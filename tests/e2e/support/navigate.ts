import { type Locator, type Page } from '@playwright/test';

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
 * ARIA for its card-per-row layout at 375px, so the role query finds nothing at the mobile viewport. The
 * tag selector is the thing that survives both of this page's layout switches.
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
