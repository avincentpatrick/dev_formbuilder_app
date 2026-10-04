import { expect, test, type Page } from '@playwright/test';
import { assertClean, forcePersonalization, forceTheme } from './support/axe';

// M130 (`R-048a3286`, `D77`) — a single choice is round buttons, and a list question takes the author's layout.
// The guest-enabled "Choice Layouts" form (E2eSeeder) holds a single choice in each of its three layouts and a
// multiple choice in the two it can take, on one page. Scanned for WCAG 2.2 AA + no horizontal overflow at all
// three viewports in light and dark — the side-by-side row and the columns grid are the reflow risk at 375px —
// and once more at the largest text size with the dyslexia face, the combination that has found spills here
// before. The guest has no session, so the shared authenticated storageState is bypassed.

test.use({ storageState: { cookies: [], origins: [] } });

const themes = ['light', 'dark'] as const;

async function openLayouts(page: Page): Promise<void> {
    await page.goto('/f/choice-layouts', { waitUntil: 'networkidle' });
    await page.getByRole('heading', { name: 'Choice Layouts', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
}

for (const theme of themes) {
    test(`Choice layouts (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await openLayouts(page);
        await forceTheme(page, theme);

        // Round buttons, not a dropdown: four radios in the question's own group, and no select on the page.
        const contact = page.getByRole('group', { name: 'How should we contact you?' });
        await expect(contact.getByRole('radio')).toHaveCount(4);
        await expect(page.getByRole('combobox')).toHaveCount(0);

        // Each list takes the layout its author chose.
        await expect(page.getByRole('group', { name: 'Visit type' }).locator('[data-choice-list]')).toHaveClass(/encode-choices--pack/);
        await expect(page.getByRole('group', { name: 'Which clinic?' }).locator('[data-choice-list]')).toHaveClass(/encode-choices--columns/);
        await expect(page.getByRole('group', { name: 'Services used' }).locator('[data-choice-list]')).toHaveClass(/encode-choices--pack/);
        await expect(page.getByRole('group', { name: 'Symptoms' }).locator('[data-choice-list]')).toHaveClass(/encode-choices--columns/);

        await assertClean(page, 'Choice layouts (initial)');

        // Choose, and the optional answer can be cleared; clearing puts focus back on the group, not the page.
        await contact.getByText('Email', { exact: true }).click();
        await expect(contact.getByRole('radio', { name: 'Email' })).toBeChecked();
        const clear = contact.getByRole('button', { name: 'Clear selection' });
        await expect(clear).toBeVisible();
        await assertClean(page, 'Choice layouts (answered)');

        await clear.click();
        await expect(contact.getByRole('radio', { name: 'Email' })).not.toBeChecked();
        await expect(clear).toHaveCount(0);
        await expect(contact.getByRole('radio', { name: 'Phone' })).toBeFocused();
    });
}

test('Choice layouts at the largest text with the dyslexia face — accessible & no horizontal overflow', async ({ page }) => {
    await openLayouts(page);
    await forcePersonalization(page, { fontSize: 'extra_large', dyslexia: true });
    await assertClean(page, 'Choice layouts (extra large, dyslexia)');
});
