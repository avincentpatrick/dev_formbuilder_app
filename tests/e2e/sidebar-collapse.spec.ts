import { expect, test, type Locator } from '@playwright/test';
import { assertClean, settlePaint } from './support/axe';

/**
 * M128 (`R-33c7fd56`) — the app sidebar collapses to its icon rail on a wide screen, and the choice is
 * remembered in the browser per device (`D68` = B).
 *
 * ⚠️ SAFE TO CLICK HERE, AND THAT IS WHY THE DECISION WENT THIS WAY. A server-side preference would live on
 * the one seeded user every spec shares, so a spec that clicked it would leak a collapsed sidebar into every
 * later desktop scan with nothing able to undo it. `localStorage` belongs to this test's own browser
 * context, which Playwright throws away afterwards.
 *
 * Desktop only: at 834 and 375 the sidebar already is a rail or a drawer and the toggle does not render.
 */

async function width(locator: Locator): Promise<number> {
    return locator.evaluate((el) => Math.round(el.getBoundingClientRect().width));
}

test.describe('the sidebar collapses on a wide screen', () => {
    test.beforeEach(({}, info) => {
        test.skip(info.project.name !== 'desktop', 'The toggle exists only above 1024px.');
    });

    test('collapses to the rail, keeps its labels reachable, and survives a reload', async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await settlePaint(page);

        const nav = page.getByRole('navigation', { name: 'Primary' });
        const collapse = nav.getByRole('button', { name: 'Collapse navigation' });
        await expect(collapse).toHaveAttribute('aria-expanded', 'true');
        await expect(collapse).toHaveAttribute('aria-controls', 'app-drawer');
        expect(await width(nav)).toBe(240);

        await collapse.click();
        await settlePaint(page);

        const expand = nav.getByRole('button', { name: 'Expand navigation' });
        await expect(expand).toHaveAttribute('aria-expanded', 'false');
        expect(await width(nav)).toBe(64);

        // The rail hides its labels from sight, so focus must bring the bubble back — the band rail's rule.
        await nav.getByRole('link').first().focus();
        await expect(page.getByRole('tooltip')).toBeVisible();

        await assertClean(page, 'the collapsed sidebar at desktop');

        await page.reload({ waitUntil: 'networkidle' });
        await settlePaint(page);
        expect(await width(nav)).toBe(64);

        await nav.getByRole('button', { name: 'Expand navigation' }).click();
        await settlePaint(page);
        expect(await width(nav)).toBe(240);
    });
});
