import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme } from './support/axe';
import { openBuilder } from './support/navigate';

// Accessibility + interaction gate for the builder's question-library picker (Increment G9b): the left-pane
// Library toggle lists the platform-seeded questions (E2eSeeder runs PlatformFieldLibrarySeeder), inserting
// one adds a materialized field to the draft, and each field's config panel exposes a one-click "Save to
// library". The full-page builder a11y is owned by builder-axe (all 3 viewports); here we scope the axe scan
// to the LEFT PANE so we cover the NEW picker UI without re-litigating the pre-existing toolbar.

const themes = ['light', 'dark'] as const;

/** This spec always drives the same seeded form; the shared helper takes the title explicitly. */
async function openSurveyBuilder(page: Page): Promise<void> {
    await openBuilder(page, 'Community Health Survey');
}

// Axe scan scoped to the left pane (the Fields ⇄ Library toggle + the active picker), mirroring builder-axe's
// tag set. Scoped rather than whole-page so it gates the new UI, not the toolbar builder-axe already covers.
async function scanLeftPane(page: Page, label: string): Promise<void> {
    await page.mouse.move(0, 0);
    const results = await new AxeBuilder({ page })
        .include('.builder__pane--left')
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
    test(`Library picker — ${theme}`, async ({ page }) => {
        await openSurveyBuilder(page);
        await page.getByRole('button', { name: 'Library' }).click();
        // Scope to the picker — the seeded form's canvas may already contain a same-named field.
        const picker = page.locator('.library');
        await expect(picker.getByRole('button', { name: /Full name/ })).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scanLeftPane(page, `library picker ${theme}`);
    });
}

test('inserting a library question adds a field to the draft', async ({ page }) => {
    await openSurveyBuilder(page);
    await page.getByRole('button', { name: 'Library' }).click();
    // Scope the insert to the picker (the canvas may hold a same-named field).
    await page.locator('.library').getByRole('button', { name: /Full name/ }).click();
    // The materialized field is auto-selected → the config Basics tab's Label input carries the item label.
    await expect(page.getByRole('textbox', { name: 'Label' })).toHaveValue('Full name', { timeout: 10_000 });
});

test('a field can be saved to the library from its config panel', async ({ page }) => {
    await openSurveyBuilder(page);
    // The builder auto-selects the first field → open its Advanced tab → Save to library (one click).
    await page.getByRole('tab', { name: 'Advanced' }).click();
    await page.getByRole('button', { name: 'Save to library' }).click();
    await expect(page.getByText(/Saved .* to your library/)).toBeVisible({ timeout: 10_000 });
});
