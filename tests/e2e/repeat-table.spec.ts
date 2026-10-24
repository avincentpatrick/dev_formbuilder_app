import { expect, test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * M185 (`R-0a7275ce`, `D129`, relayed `R1-7b`) — a repeatable section laid out as a table: every question a column, every
 * entry a row. The guest-enabled "Household Table" (E2eSeeder) is one; in a real browser, at each project's width, it is
 * a table where the section has 30em of its own and a card per entry below that — the same DOM — and either way every cell
 * is a labelled control, the page does not scroll sideways, and Add row / Remove keep the keyboard on the table.
 *
 * jsdom draws no layout, so this is where the container query is actually measured: the header row is `position: static`
 * (drawn) in the table and `absolute` (kept for a screen reader, hidden from sight) in the cards.
 */
for (const theme of ['light', 'dark'] as const) {
    test(`Public runtime — a repeatable section as a table (${theme}): a row per entry, cards on a phone, accessible`, async ({ page }) => {
        await page.goto('/f/household-table', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Household Table', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Household Table (empty)');

        await page.getByRole('button', { name: 'Add Household members' }).click();
        const table = page.locator('[data-repeat-table]');
        await expect(table).toBeVisible({ timeout: 10_000 });
        await expect(page.getByRole('textbox', { name: /Member name/ })).toBeFocused();
        await page.getByRole('textbox', { name: /Member name/ }).fill('Ana Cruz');
        await page.getByRole('spinbutton', { name: /Age/ }).fill('34');
        await page.getByRole('button', { name: 'Add Household members' }).click();
        await expect(table.locator('tbody tr')).toHaveCount(2);

        const width = page.viewportSize()?.width ?? 1280;
        const head = await table.locator('thead').evaluate((el) => getComputedStyle(el).position);
        // The guest column is 44rem at most; the section is a table once it has 30em of its own.
        expect(head, `the header row at ${width}px`).toBe(width >= 600 ? 'static' : 'absolute');
        await expect(table.locator('tbody tr').first().locator('th')).toHaveText('Household members 1');

        await assertClean(page, `Household Table (two entries, ${width}px)`);

        await table.locator('tbody tr').nth(1).getByRole('button', { name: 'Remove Household members 2' }).click();
        await expect(table.locator('tbody tr')).toHaveCount(1);
        await expect(table.getByRole('button', { name: 'Remove Household members 1' })).toBeFocused();
    });
}
