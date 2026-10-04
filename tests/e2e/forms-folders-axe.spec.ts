import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { assertClean, forceTheme, settleAnimations } from './support/axe';
import { formEntry } from './support/navigate';

/*
 * M131 (`R-9e634897`, `D78`/`D79`) — forms-list folders: the folder filter, the card caption, "Manage folders"
 * and "Move to folder", in both themes at every viewport project.
 *
 * ⚠️ THIS SPEC CHANGES NOTHING. It runs once per viewport project against ONE seeded database, so it opens
 * each dialog and cancels it rather than creating, renaming or filing anything — a write here would move the
 * counts every later project (and `responsive-axe`'s forms-list scan) reads. The writes are covered by
 * `FormFolderTest` and `FormFolderAssignmentTest`, and by the dialogs' own Vitest files.
 *
 * The page scan is `assertClean` (whole page, overflow included) because `/forms` is already clean there;
 * the dialogs are scanned SCOPED to the dialog, per the G9b lesson, so a pre-existing finding elsewhere on the
 * page could not be mistaken for this increment's.
 */

const themes = ['light', 'dark'] as const;

async function scanDialog(page: Page, label: string): Promise<void> {
    await page.mouse.move(0, 0);
    await settleAnimations(page);

    const results = await new AxeBuilder({ page })
        .include('[role="dialog"]')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(
        results.violations,
        `${label}\n` +
            results.violations
                .map((v) => `${v.id}: ${v.help} → ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)
                .join('\n'),
    ).toEqual([]);
}

for (const theme of themes) {
    test(`Forms — filtered to a folder (${theme})`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await forceTheme(page, theme);

        // The seeded folders: Clinics holds Clinic Intake, Field surveys holds Household Roster, Archive is empty.
        await page.getByLabel('Folder', { exact: true }).selectOption({ label: 'Clinics (1)' });
        await expect(page).toHaveURL(/[?&]folder=/);
        await expect(formEntry(page, 'Clinic Intake')).toBeVisible();
        await expect(formEntry(page, 'Household Roster')).toHaveCount(0);

        // The caption names the folder on the card (grid is the default view).
        await expect(formEntry(page, 'Clinic Intake').locator('.form-card__folder')).toContainText('Clinics');

        await assertClean(page, `forms list, filtered to a folder (${theme})`);
    });

    test(`Forms — an empty folder says "no matches" (${theme})`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await forceTheme(page, theme);

        await page.getByLabel('Folder', { exact: true }).selectOption({ label: 'Archive (0)' });
        await expect(page.getByRole('heading', { name: 'No matching forms' })).toBeVisible();

        await assertClean(page, `forms list, empty folder (${theme})`);
    });

    test(`Forms — Manage folders and Move to folder dialogs (${theme})`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await forceTheme(page, theme);

        await page.getByRole('button', { name: 'Manage folders' }).click();
        const manage = page.getByRole('dialog', { name: 'Manage folders' });
        await expect(manage).toBeVisible();
        await expect(manage.locator('[data-folder-entry]')).toHaveCount(3);
        await scanDialog(page, `Manage folders (${theme})`);

        // The delete confirmation, opened and cancelled — it must say no form is deleted.
        await manage.getByRole('button', { name: 'Delete Archive' }).click();
        await expect(manage).toContainText('Its forms move to Unfiled. No form is deleted.');
        await scanDialog(page, `Manage folders, delete confirmation (${theme})`);
        await manage.getByRole('button', { name: 'Cancel' }).click();
        await manage.getByRole('button', { name: 'Done' }).click();
        await expect(manage).toBeHidden();

        await formEntry(page, 'Clinic Intake').getByRole('button', { name: 'Move to folder' }).click();
        const move = page.getByRole('dialog', { name: 'Move to folder' });
        await expect(move).toBeVisible();
        await expect(move.getByLabel('Folder', { exact: true })).toHaveValue(/.+/);
        await scanDialog(page, `Move to folder (${theme})`);
        await move.getByRole('button', { name: 'Cancel' }).click();
        await expect(move).toBeHidden();
    });
}
