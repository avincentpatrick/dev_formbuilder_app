import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme, settlePaint } from './support/axe';

/*
 * The data explorer (M236, ADR-0025) — the board of saved charts and the chart builder.
 *
 * SCOPED scans, like analytics-axe: each is narrowed to the page's own root and drops the
 * horizontal-overflow check, which responsive-axe owns for both pages as whole-page scans. `scanPane` is
 * duplicated from analytics-axe for the reason that file gives.
 *
 * ── WHAT AXE CANNOT SEE ────────────────────────────────────────────────────────────────────────────────
 * ADR-0011 §D12: axe passes a chart that is a picture with a plausible `aria-label` and no data behind it.
 * The table-alternative contract of the two chart primitives this increment adds is pinned by their Vitest
 * suites, and of the page's use of them by `explore-components.test.ts`. The last test here is the
 * integration twin: that the tables are in the real DOM of a real page.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * This spec CREATES NOTHING. Every project shares one seeded database and `explorer_charts` has a unique
 * name per owner, so a run that died between a save and a delete would turn the next project's run red
 * for a reason that looks like a page bug. E2eSeeder seeds the owner's three charts — one multi-series, one
 * shared donut, one deliberately unreadable — and the builder is driven without ever pressing Save.
 */

const themes = ['light', 'dark'] as const;

async function scanPane(page: Page, selector: string, label: string): Promise<void> {
    await page.mouse.move(0, 0);

    // Wait for the un-hover to PAINT — see `support/axe.ts`'s `assertClean`.
    await settlePaint(page);
    const results = await new AxeBuilder({ page })
        .include(selector)
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

/** Open the board and wait for every tile to have settled into a chart or a sentence. */
async function openBoard(page: Page): Promise<void> {
    await page.goto('/explore', { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'Your charts' })).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.explore [aria-busy="true"]')).toHaveCount(0, { timeout: 10_000 });
}

/** Open the builder and wait for its first preview to have been drawn. */
async function openBuilder(page: Page): Promise<void> {
    await page.goto('/explore/new', { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'Preview' })).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.explore-builder .mds-bar')).toBeVisible({ timeout: 10_000 });
}

for (const theme of themes) {
    test(`Explore — the board, with a chart that draws and one that is refused (${theme})`, async ({ page }) => {
        await openBoard(page);
        await forceTheme(page, theme);

        // The three tile states the seeder exists to put on one screen.
        await expect(page.locator('.explore .mds-col')).toBeVisible();
        await expect(page.locator('.explore .mds-donut')).toBeVisible();
        await expect(page.getByText('This chart cannot be drawn')).toBeVisible();

        // A refused chart is one tile's problem: the page did not fall over, and the other two drew.
        await expect(page.getByRole('link', { name: 'Legacy chart' })).toBeVisible();

        await scanPane(page, '.explore', `explore board ${theme}`);
    });

    test(`Explore — the builder and its first preview (${theme})`, async ({ page }) => {
        await openBuilder(page);
        await forceTheme(page, theme);

        // Every control a reader reaches first has a real label, not a placeholder standing in for one.
        await expect(page.getByLabel('Data source')).toBeVisible();
        await expect(page.getByLabel('Chart type')).toBeVisible();
        await expect(page.getByLabel('Group by')).toBeVisible();
        await expect(page.getByLabel('From')).toBeVisible();

        await scanPane(page, '.explore-builder', `explore builder ${theme}`);
    });

    test(`Explore — a multi-series chart, a filter and the half-built prompt (${theme})`, async ({ page }) => {
        await openBuilder(page);
        await forceTheme(page, theme);

        // Columns split by a second field: the legend and five-colour series are the contrast risk here.
        await page.getByLabel('Chart type').selectOption('column');
        await page.getByLabel('Split by').selectOption('source');
        await expect(page.locator('.explore-builder .mds-col')).toBeVisible();
        await expect(page.locator('.explore-builder .mds-chart-legend')).toBeVisible();

        await scanPane(page, '.explore-builder', `explore builder, split columns ${theme}`);

        // A filter with no value yet: the chart gives way to a prompt, and Save is not on offer.
        await page.getByRole('button', { name: 'Add a filter' }).click();
        await expect(page.getByRole('group', { name: 'Filter 1', exact: true })).toBeVisible();
        await expect(page.getByText('Give each filter a value, or remove it.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save chart' })).toBeDisabled();

        await scanPane(page, '.explore-builder', `explore builder, empty filter ${theme}`);
    });

    test(`Explore — the save dialog (${theme})`, async ({ page }) => {
        await openBuilder(page);
        await forceTheme(page, theme);

        await page.getByRole('button', { name: 'Save chart' }).click();

        const dialog = page.getByRole('dialog', { name: 'Save this chart' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Name')).toBeVisible();
        await expect(dialog.getByRole('switch', { name: 'Share with the workspace' })).toBeVisible();

        await scanPane(page, '[role="dialog"]', `explore save dialog ${theme}`);

        // Closed without saving: this spec creates nothing.
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
    });
}

test('Explore — every drawn chart has its data in a table in the real DOM', async ({ page }) => {
    await openBoard(page);

    // The integration twin of the Vitest table contract: a tile that drew a chart carries a table of what
    // it drew, captioned with the chart's own name.
    for (const name of ['Responses by status and channel', 'Responses by form']) {
        await expect(page.locator('.explore table caption', { hasText: name })).toHaveCount(1);
    }

    await openBuilder(page);

    // Clipped from sight by default and there for assistive technology either way; the switch shows it.
    // Asserted on the clip class and not on the viewport: at 375px the chart sits above a long column of
    // controls, so "in the viewport" would be a statement about scroll position, not about the table.
    const table = page.locator('.explore-builder .mds-bar table');
    const clipped = page.locator('.explore-builder .mds-bar .mds-bar__table-wrap--sr');
    await expect(table).toHaveCount(1);
    await expect(clipped).toHaveCount(1);

    await page.locator('label', { hasText: 'Show the numbers' }).click();
    await expect(clipped).toHaveCount(0);
    await table.scrollIntoViewIfNeeded();
    await expect(table.locator('tbody tr').first()).toBeVisible();
});
