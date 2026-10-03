import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';
import { showBuilderPane } from './support/navigate';

/*
 * A note's Content tab (M129 — `R-6dedc3a9`'s editor half, `R-f0c5b682`'s images), scanned WITH blocks in it: a
 * heading, a paragraph carrying bold and a link, and an uploaded image with its description. The empty tab is two
 * sentences and a row of buttons; the controls that need scanning only exist once an author has composed something.
 *
 * ── STATE: IT MAKES ITS OWN FORM, AND ARCHIVES IT ─────────────────────────────────────────────────────
 * Every spec shares one seeded database across three viewport projects, so composing into a seeded form would change
 * what the next spec scans. Each case creates a form with a unique title through the real "New form" dialog, works
 * only inside it, and archives it in a `finally`, so the seeder is not touched and a failed scan still cleans up.
 *
 * The image is a real 1x1 PNG sent through the editor's own file field. The virus check runs inline where the queue
 * is `sync` (CI); where a worker runs it, the editor says "Checking this file" and asks again, which the wait for
 * the image allows for.
 */

const themes = ['light', 'dark'] as const;

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

async function createForm(page: Page, title: string): Promise<string> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'New form' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Title').fill(title);
    await dialog.getByRole('button', { name: 'Create form' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/builder$/, { timeout: 30_000 });

    return /\/forms\/([0-9a-f-]{36})\/builder$/.exec(page.url())![1];
}

/** Archive the form this spec made, through the same route the forms list uses. */
async function archive(page: Page, formId: string): Promise<void> {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post(`/forms/${formId}/archive`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
    });
    expect(response.status(), 'the form this spec made is archived again').toBeLessThan(400);
}

for (const theme of themes) {
    test(`Builder — a note's Content tab, composed (${theme})`, async ({ page }, info) => {
        const formId = await createForm(page, `Content tab ${theme} ${info.project.name} ${Date.now()}`);

        try {
            await showBuilderPane(page, 'fields');
            await page.getByRole('button', { name: 'Note / label', exact: true }).click();
            await showBuilderPane(page, 'settings');
            await expect(page.getByRole('tab')).toHaveText(['Basics', 'Content', 'Advanced']);
            await page.getByRole('tab', { name: 'Content' }).click();

            const editor = page.locator('.cbe');
            await expect(editor.locator('[data-content-notice="not-shown-yet"]')).toBeVisible();
            const add = editor.getByRole('group', { name: 'Add a block' });

            await add.getByRole('button', { name: 'Heading' }).click();
            await editor.getByLabel('Heading text').fill('Before you begin');
            await add.getByRole('button', { name: 'Paragraph' }).click();
            await editor.getByLabel('Text', { exact: true }).fill('Read the **consent form** first, or [ask us](https://example.org).');
            await editor.getByLabel('Add an image').setInputFiles({ name: 'map.png', mimeType: 'image/png', buffer: PNG });
            await expect(editor.locator('.cbe__image')).toBeVisible({ timeout: 20_000 });
            await editor.getByLabel('Description').fill('A map of the clinic entrance');
            await expect(editor.locator('[data-block-type]')).toHaveCount(3);

            await forceTheme(page, theme);
            await assertClean(page, `note content tab, composed (${theme})`);
        } finally {
            await archive(page, formId);
        }
    });
}
