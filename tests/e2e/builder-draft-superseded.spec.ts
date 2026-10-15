import { test, expect, type Locator, type Page, type Response } from '@playwright/test';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M172 (`R-81756e95`) — the testers' report, 2026-10-14: "The key has already been taken. … has a change that was not
 * saved. Select it to see why", and on a delete "That item belongs to a published version and can no longer be edited".
 *
 * A publish clones the draft into a new one with NEW ids and the SAME keys. A builder page left open across it — a publish
 * from the hub in another tab, or the browser's Back button after publishing from the hub — still held the old ids, so its
 * next save sent an unchanged key against the clone and was refused on the key. Now every write names the draft the page
 * was loaded with: a superseded page is told once ("This form was published since this page loaded"), reloads, and the
 * edit it was holding is put back in the new draft by key. A builder brought back by Back re-visits the server at once
 * (`history-restore.ts`) instead of showing the props of its first visit.
 *
 * Desktop only: the save path is the same at every width. Each case makes its own form and archives it (M139's reason:
 * one seeded database, three projects).
 */

const QUESTION = 'Occupation';

/** The next PATCH of a question on this form whose body satisfies `carries` — start waiting BEFORE the change. */
function patched(page: Page, formId: string, carries: (body: Record<string, unknown>) => boolean): Promise<Response> {
    return page.waitForResponse(
        (response) => {
            const request = response.request();
            if (request.method() !== 'PATCH' || !response.url().includes(`/forms/${formId}/fields/`)) return false;
            const body = request.postDataJSON() as Record<string, unknown> | null;

            return body !== null && carries(body);
        },
        { timeout: 30_000 },
    );
}

async function labelField(page: Page): Promise<Locator> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name: 'Basics', exact: true }).click();

    return page.getByLabel('Label', { exact: true });
}

/** A Text question from the palette, labelled and saved — publishing needs one. */
async function addQuestion(page: Page, formId: string): Promise<void> {
    await showBuilderPane(page, 'fields');
    await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
    await page.waitForTimeout(800);
    await saved(page);
    const named = patched(page, formId, (body) => body.label === QUESTION);
    await (await labelField(page)).fill(QUESTION);
    expect((await named).ok(), 'the question is labelled and saved before the publish').toBe(true);
    await saved(page);
}

/** The hub's "Publish draft", and the publish's own POST answered. */
async function publishFromHub(hub: Page, formId: string): Promise<void> {
    const published = hub.waitForResponse(
        (response) => response.request().method() === 'POST' && response.url().endsWith(`/forms/${formId}/publish`),
        { timeout: 30_000 },
    );
    await hub.getByRole('button', { name: 'Publish draft' }).click();
    expect((await published).status(), 'the publish was accepted').toBeLessThan(400);
    // The hub drawn AFTER the publish (its redirect), so the publish has landed before anything else is done in this tab.
    await expect(hub.getByText(/v\d+ live/)).toBeVisible({ timeout: 30_000 });
}

/** The form's one question, selected from the Structure view — a page Back restores may have nothing selected. */
async function openQuestion(page: Page): Promise<void> {
    await page.locator('.builder__panes').waitFor({ timeout: 30_000 });
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Structure').click();
    await page.locator('.canvas__field-main').first().click();
}

/** What the question's label reads after a fresh load of the builder — what the server holds. */
async function labelOnServer(page: Page): Promise<string> {
    await page.reload({ waitUntil: 'load' });
    await openQuestion(page);

    return (await labelField(page)).inputValue();
}

test('Builder — a tab left open while another publishes the form says so, reloads, and keeps the edit', async ({ page, context }, info) => {
    test.skip(info.project.name !== 'desktop', 'the save path is the same at every width');
    test.setTimeout(240_000);

    const formId = await createForm(page, `Draft superseded ${Date.now()}`);
    try {
        await addQuestion(page, formId);

        // Another tab publishes the form from its hub while this one stays open on the builder.
        const hub = await context.newPage();
        await hub.goto(`/forms/${formId}`, { waitUntil: 'networkidle' });
        await publishFromHub(hub, formId);
        await hub.close();

        // This tab edits on. Before M172 this save was refused "The key has already been taken." (a 422).
        const refused = patched(page, formId, (body) => body.label === 'Occupation now');
        await (await labelField(page)).fill('Occupation now');
        expect((await refused).status(), 'the stale page is refused as superseded, not on its key').toBe(409);

        const dialog = page.getByRole('dialog', { name: 'This form was published' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('This form was published since this page loaded. Reload to keep editing.');
        await expect(page.getByText('The key has already been taken')).toHaveCount(0);

        // Reload: the page comes back on the new draft and the kept edit is saved there.
        const replayed = patched(page, formId, (body) => body.label === 'Occupation now');
        await dialog.getByRole('button', { name: 'Reload', exact: true }).click();
        expect((await replayed).ok(), 'the kept edit is saved into the new draft').toBe(true);
        await saved(page);
        await expect(page.getByRole('dialog', { name: 'This form was published' })).toHaveCount(0);

        expect(await labelOnServer(page)).toBe('Occupation now');
    } finally {
        await archive(page, formId);
    }
});

test('Builder — Back to the builder after publishing from the hub shows the new draft, never the old one', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the save path is the same at every width');
    test.setTimeout(240_000);

    const title = `Draft superseded back ${Date.now()}`;
    const formId = await createForm(page, title);
    try {
        await addQuestion(page, formId);

        // To the hub through the builder's own trail (an Inertia visit, so Back is Inertia's history restore), and publish.
        await page.getByRole('navigation', { name: 'Breadcrumb' }).getByRole('link', { name: title }).click();
        await page.waitForURL(new RegExp(`/forms/${formId}$`), { timeout: 30_000 });
        await publishFromHub(page, formId);

        for (let step = 0; step < 3 && !page.url().endsWith('/builder'); step++) await page.goBack();
        expect(page.url(), 'Back reached the builder').toMatch(/\/builder$/);
        // Measured on the trunk: Inertia restored the builder with the props it was FIRST loaded with — no question at all,
        // since the question was added by the builder's own writes after that visit. The page must be the server's now.
        await page.locator('.builder__panes').waitFor({ timeout: 30_000 });
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Structure').click();
        await expect(page.locator('.canvas__field-main', { hasText: QUESTION }), 'the page Back shows holds the question the server holds').toBeVisible({ timeout: 30_000 });
        await openQuestion(page);

        // The page Back showed must already be the NEW draft: its first save lands, with no dialog and no key refusal.
        const first = patched(page, formId, (body) => body.label === 'Occupation after Back');
        await (await labelField(page)).fill('Occupation after Back');
        expect((await first).status(), 'the page Back restored holds the new draft').toBe(200);
        await saved(page);
        await expect(page.getByRole('dialog', { name: 'This form was published' })).toHaveCount(0);
        await expect(page.getByText('The key has already been taken')).toHaveCount(0);

        expect(await labelOnServer(page)).toBe('Occupation after Back');
    } finally {
        await archive(page, formId);
    }
});
