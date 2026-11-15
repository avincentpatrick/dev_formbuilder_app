import { test, expect, type Page } from '@playwright/test';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M242 (`R-ade0b598`, `D226`) — a renamed key carries every text that names it, in a real browser.
 *
 * "Greeting" pipes "Your name" into its label as `${your_name}`. Renaming Your name's key used to leave that reference
 * pointing at nothing until publish refused it. Now the server rewrites it, the builder takes the rewritten question in
 * without a second save, the panel says so under Field key, and one Undo puts both back.
 *
 * Desktop only: the settings panel is the same at every width, and the panes switcher is not what is under test.
 * It makes its own form and archives it (one seeded database, three projects).
 */

test.use({ actionTimeout: 15_000 });

/** The autosave debounces 600 ms, so "All changes saved" read earlier than that is the PREVIOUS save's. */
async function settled(page: Page): Promise<void> {
    await page.waitForTimeout(800);
    await saved(page);
}

async function configTab(page: Page, name: string): Promise<void> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name, exact: true }).click();
}

async function addText(page: Page, label: string, key: string): Promise<void> {
    await showBuilderPane(page, 'fields');
    await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
    await settled(page);
    await configTab(page, 'Basics');
    await page.getByLabel('Label', { exact: true }).fill(label);
    await configTab(page, 'Advanced');
    await page.getByLabel('Field key', { exact: true }).fill(key);
    await settled(page);
}

async function pick(page: Page, label: string): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('[data-field-uid]').filter({ hasText: label }).locator('.canvas__field-main').click();
}

test('Builder — renaming a key carries the label that pipes it, says so, and one Undo puts both back', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the settings panel is the same at every width');
    test.setTimeout(180_000);
    const formId = await createForm(page, `Key rename ${Date.now()}`);

    try {
        await addText(page, 'Your name', 'your_name');
        await addText(page, 'Hello ${your_name}', 'greeting');

        await pick(page, 'Your name');
        await configTab(page, 'Advanced');
        const key = page.getByLabel('Field key', { exact: true });
        await expect(key).toHaveValue('your_name');
        await key.fill('full_name');
        await settled(page);

        await expect(page.locator('[data-carry-notice]')).toHaveText('1 other question that named “your_name” now names “full_name”.');

        await pick(page, 'Hello');
        await configTab(page, 'Basics');
        await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Hello ${full_name}');

        // The carried question saves again without a conflict: its token came with the carry.
        await page.getByLabel('Label', { exact: true }).fill('Hello there, ${full_name}');
        await settled(page);
        await expect(page.getByRole('dialog')).toHaveCount(0);

        // Undo the label, then the rename: the rename's undo carries the reference back.
        await page.getByRole('button', { name: 'Undo' }).click();
        await settled(page);
        await page.getByRole('button', { name: 'Undo' }).click();
        await settled(page);
        await pick(page, 'Hello');
        await configTab(page, 'Basics');
        await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Hello ${your_name}');

        // And it was all saved: after a reload the reference names the key the question holds.
        await page.reload({ waitUntil: 'networkidle' });
        await pick(page, 'Hello');
        await configTab(page, 'Basics');
        await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Hello ${your_name}');
    } finally {
        await archive(page, formId);
    }
});
