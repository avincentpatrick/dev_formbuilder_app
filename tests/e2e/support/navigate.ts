import { type Page } from '@playwright/test';

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
