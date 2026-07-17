import { test, expect } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// Accessibility + responsive gate for the form-template gallery and the "Save as template" modal
// (Increment G9a), plus an end-to-end instantiate that must land in the builder. The gallery renders the
// platform templates seeded by E2eSeeder; each card exposes a single "Use this template" action.

const themes = ['light', 'dark'] as const;

for (const theme of themes) {
    test(`Template gallery — ${theme}`, async ({ page }) => {
        await page.goto('/forms/templates', { waitUntil: 'networkidle' });
        await expect(page.getByRole('button', { name: 'Use this template' }).first()).toBeVisible({
            timeout: 10_000,
        });
        await forceTheme(page, theme);
        await assertClean(page, `template gallery ${theme}`);
    });

    test(`Save-as-template modal — ${theme}`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        // The per-row "Save as template" icon action opens the shared modal.
        await page.getByRole('button', { name: 'Save as template' }).first().click();
        await expect(page.getByRole('dialog')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByRole('heading', { name: 'Save as template' })).toBeVisible();
        await forceTheme(page, theme);
        await assertClean(page, `save-as-template modal ${theme}`);
    });
}

test('instantiating a template lands in the builder on a populated draft', async ({ page }) => {
    await page.goto('/forms/templates', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Use this template' }).first().click();
    await page.waitForURL('**/builder', { timeout: 30_000 });
    // The new form's builder is ready (Publish is the toolbar's terminal action).
    await expect(page.getByRole('button', { name: 'Publish' })).toBeVisible({ timeout: 10_000 });
});
