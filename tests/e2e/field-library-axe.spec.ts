import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// Accessibility + interaction gate for the builder's question-library picker (Increment G9b): the left-pane
// Library toggle lists the platform-seeded questions (E2eSeeder runs PlatformFieldLibrarySeeder), inserting
// one adds a materialized field to the draft, and each field's config panel exposes a one-click "Save to
// library". Navigates via the forms list to a seeded draft form, exactly like builder-axe.

const themes = ['light', 'dark'] as const;

async function openBuilder(page: Page): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Community Health Survey' }).click();
    await page.waitForURL('**/builder', { timeout: 30_000 });
}

for (const theme of themes) {
    test(`Library picker — ${theme}`, async ({ page }) => {
        await openBuilder(page);
        await page.getByRole('button', { name: 'Library' }).click();
        // Scope to the picker — the seeded form's canvas may already contain a same-named field.
        const picker = page.locator('.library');
        await expect(picker.getByRole('button', { name: /Full name/ })).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, `library picker ${theme}`);
    });
}

test('inserting a library question adds a field to the draft', async ({ page }) => {
    await openBuilder(page);
    await page.getByRole('button', { name: 'Library' }).click();
    // Scope the insert to the picker (the canvas may hold a same-named field).
    await page.locator('.library').getByRole('button', { name: /Full name/ }).click();
    // The materialized field is auto-selected → the config Basics tab's Label input carries the item label.
    await expect(page.getByRole('textbox', { name: 'Label' })).toHaveValue('Full name', { timeout: 10_000 });
});

test('a field can be saved to the library from its config panel', async ({ page }) => {
    await openBuilder(page);
    // The builder auto-selects the first field → open its Advanced tab → Save to library (one click).
    await page.getByRole('tab', { name: 'Advanced' }).click();
    await page.getByRole('button', { name: 'Save to library' }).click();
    await expect(page.getByText(/Saved .* to your library/)).toBeVisible({ timeout: 10_000 });
});
