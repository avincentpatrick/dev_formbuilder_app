import { test, expect, type Page } from '@playwright/test';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M178 (`R-75719ac4`) — the "Insert a question" picker, in a real browser. The testers (2026-10-11, comment 6): "direct
 * reference the field (not the entire field question but the field code should be enough)"; the user: "all the type of
 * question must include the referencing".
 *
 * ⛔ THE CASE ONLY A BROWSER CAN SETTLE: the picker writes through `execCommand('insertText')` so the box's OWN undo takes
 * the insert back in one step. happy-dom has no `execCommand`, so Vitest runs only the fallback, which would empty the
 * undo stack — a Ctrl+Z here that removed more, or nothing, is that fallback having run where it must not.
 *
 * Desktop only: the picker sits in the settings panel at every width, and the panes switcher is not what is under test.
 * It makes its own form and archives it (M139's reason: one seeded database, three projects).
 */

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
    await configTab(page, 'Basics');
}

test('Builder — Insert a question writes the code at the caret, one undo takes it back, and typing @ picks one too', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the picker is the same at every width');
    test.setTimeout(180_000);
    const formId = await createForm(page, `Insert a question ${Date.now()}`);

    try {
        await addText(page, 'Your name', 'your_name');
        await addText(page, 'How old is', 'age_question');

        const label = page.getByLabel('Label', { exact: true });
        await label.click();
        await label.press('End');
        await label.pressSequentially(' ?');
        await label.press('ArrowLeft'); // the caret between the space and the question mark

        await page.getByRole('button', { name: 'Insert a question' }).first().click();
        const search = page.getByRole('combobox', { name: 'Find a question' });
        await search.fill('name');
        await expect(page.getByRole('option')).toHaveText(['Your name · your_name']);
        await search.press('Enter');

        await expect(label).toHaveValue('How old is ${your_name}?');

        // ONE undo step, the box's own: the insert goes, and what was typed before it stays.
        await label.focus();
        await page.keyboard.press('Control+z');
        await expect(label).toHaveValue('How old is ?');

        // Typing @ opens the same list under the box; Enter picks the highlighted question in place of "@you".
        await label.press('ArrowLeft');
        await label.press('End');
        await label.press('ArrowLeft');
        await label.pressSequentially('@you');
        const list = page.getByRole('listbox', { name: 'Questions' });
        await expect(list.getByRole('option')).toHaveText(['Your name · your_name']);
        await label.press('Enter');

        await expect(label).toHaveValue('How old is ${your_name}?');
        await expect(list).toHaveCount(0);
        await settled(page);
    } finally {
        await archive(page, formId);
    }
});
