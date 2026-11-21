import { expect, test, type Locator, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// M247 (`grid-wide-sticky-explorer`, `D231`) — "Wide Table" (E2eSeeder), a stepped guest form: page 1 keeps the reading
// width (44rem), and page 2 — a section the author marked "Wide page" — widens to the scanned-paper view's 90rem column
// from a 64rem viewport, so its 14-row, 7-column grid is a real table. The table keeps its header row and its first
// column in view while it scrolls in its own box, and a focused cell is never hidden under them. A phone is unchanged:
// the grid is a card per row there.

test.use({ storageState: { cookies: [], origins: [] } });

const themes = ['light', 'dark'] as const;

const columnWidth = (page: Page): Promise<number> => page.locator('.runtime__column').evaluate((el) => el.getBoundingClientRect().width);

async function box(locator: Locator): Promise<{ x: number; y: number; width: number; height: number }> {
    const b = await locator.boundingBox();
    expect(b, 'element has no box').not.toBeNull();

    return b!;
}

for (const theme of themes) {
    test(`Wide table (${theme}) — the page widens for its table, which keeps its header row and first column in view`, async ({ page }) => {
        const width = page.viewportSize()?.width ?? 0;

        await page.goto('/f/wide-table', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Wide Table', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);

        // Page 1 is an ordinary section: the reading width at every viewport.
        expect(await columnWidth(page)).toBeLessThanOrEqual(705);
        await assertClean(page, 'Wide table (page 1, the reading width)');

        await page.getByRole('button', { name: 'Next', exact: true }).click();
        const grid = page.locator('[data-matrix-grid]').filter({ hasText: 'Indicators to review' });
        await grid.waitFor({ state: 'visible' });
        await expect(grid.getByText('Visits to a clinic per 1,000 people each year.')).toBeVisible();

        const scroll = grid.locator('[data-grid-scroll]');
        const firstLabel = grid.locator('.mds-grid__row-label').first();

        if (width < 834) {
            // The phone: a card per row, the column headers out of sight, nothing pinned, nothing wider.
            expect(await columnWidth(page)).toBeLessThanOrEqual(705);
            expect(await grid.locator('.mds-grid__head').evaluate((el) => getComputedStyle(el).position)).toBe('absolute');
            expect(await firstLabel.evaluate((el) => getComputedStyle(el).position)).toBe('static');
            await assertClean(page, 'Wide table (page 2, cards on a phone)');

            return;
        }

        // From 64rem the wide section takes the 90rem column, and the banner above it widens with it; below, the reading width.
        if (width >= 1024) {
            expect(await columnWidth(page)).toBeGreaterThan(1200);
            await expect(page.locator('.app-shell__banner')).toHaveClass(/app-shell__banner--wide/);
        } else {
            expect(await columnWidth(page)).toBeLessThanOrEqual(705);
        }

        // A table: the first column pinned and opaque, the header row pinned.
        expect(await firstLabel.evaluate((el) => getComputedStyle(el).position)).toBe('sticky');
        expect(await firstLabel.evaluate((el) => getComputedStyle(el).backgroundColor)).not.toMatch(/rgba\(0, 0, 0, 0\)|transparent/);
        expect(await grid.locator('.mds-grid__col').first().evaluate((el) => getComputedStyle(el).position)).toBe('sticky');

        // Fourteen rows on a tall-enough screen scroll in a box of their own (75dvh), which a keyboard can reach.
        const sizes = await scroll.evaluate((el) => ({ scrollHeight: el.scrollHeight, clientHeight: el.clientHeight }));
        expect(sizes.scrollHeight, 'the long table is not in a box of its own').toBeGreaterThan(sizes.clientHeight + 10);
        await expect(scroll).toHaveAttribute('tabindex', '0');
        await expect(scroll).toHaveAttribute('role', 'group');
        await expect(scroll).toHaveAttribute('aria-label', 'Indicators to review');

        // Scrolled to its last row, the header row is still at the top of the box, and the first row has gone by.
        const header = grid.locator('.mds-grid__col').first();
        await scroll.evaluate((el) => {
            el.scrollTop = el.scrollHeight;
        });
        const boxTop = (await box(scroll)).y;
        expect(Math.abs((await box(header)).y - boxTop)).toBeLessThan(2);
        expect((await box(firstLabel)).y).toBeLessThan(boxTop);

        // Where the table is wider than its box, scrolled sideways, the first column stays at the box's start.
        const across = await scroll.evaluate((el) => el.scrollWidth > el.clientWidth + 1);
        if (across) {
            const lastLabel = grid.locator('.mds-grid__row-label').last();
            await scroll.evaluate((el) => {
                el.scrollLeft = el.scrollWidth;
            });
            expect(Math.abs((await box(lastLabel)).x - (await box(scroll)).x)).toBeLessThan(2);
        }

        // From the far end of the box, a cell near its top-left reached by focus is scrolled back into view CLEAR of the
        // pinned header row and first column — the case scroll padding exists for (WCAG 2.4.11).
        await scroll.evaluate((el) => {
            el.scrollTop = el.scrollHeight;
            el.scrollLeft = el.scrollWidth;
        });
        const cell = page.getByRole('combobox', { name: 'I02 Indicator 2 Used locally', exact: true });
        await cell.focus();
        const hit = await cell.evaluate((el) => {
            const r = el.getBoundingClientRect();
            const top = document.elementFromPoint(r.left + r.width / 2, r.top + 4);
            const start = document.elementFromPoint(r.left + 4, r.top + r.height / 2);

            return [top !== null && (top === el || el.contains(top)), start !== null && (start === el || el.contains(start))];
        });
        expect(hit).toEqual([true, true]);

        await assertClean(page, 'Wide table (page 2, a wide table)');
    });
}
