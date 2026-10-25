import { expect, test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * M186 (`R-70c7673a`, relayed `R1-2`) — a cascade on the built-in Philippine places, in a real browser. The guest-enabled
 * "Barangay Visit" (E2eSeeder) takes its four levels from the places the E2E environment holds (`tests/fixtures/psgc/`,
 * a small fixture of every shape the build handles), and fills in a level with one choice. Picking the City of Manila — a
 * city outside any province, its own one choice at the city level — shows the city level as text, already filled in,
 * and opens the barangay list on Manila's barangays in natural order; the answer saves with the city's code at both levels.
 * Scanned for WCAG 2.2 AA and horizontal overflow at each project's width, light and dark. The guest has no session.
 */
test.use({ storageState: { cookies: [], origins: [] } });

for (const theme of ['light', 'dark'] as const) {
    test(`Public runtime — the Philippine places (${theme}): a city outside any province fills itself in, accessible`, async ({ page }) => {
        await page.goto('/f/barangay-visit', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Barangay Visit', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);

        const region = page.getByRole('combobox', { name: 'Region' });
        await expect(region.locator('option')).toHaveText(['Select an option', 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)', 'National Capital Region (NCR)', 'Region I (Ilocos Region)']);
        await assertClean(page, 'Barangay Visit (empty)');

        await region.selectOption('1300000000');
        const province = page.getByRole('combobox', { name: 'Province or district' });
        await expect(province.locator('option')).toHaveText(['Select an option', 'City of Manila', 'Pateros']);
        await province.selectOption('1380600000');

        // The city level is the City of Manila's one choice: shown as text, never a select to pick from.
        await expect(page.locator('[data-implied]')).toHaveText('City of Manila');
        await expect(page.getByRole('combobox', { name: 'City or municipality' })).toHaveCount(0);

        const barangay = page.getByRole('combobox', { name: 'Barangay' });
        await expect(barangay.locator('option')).toHaveText(['Select an option', 'Barangay 1', 'Barangay 2', 'Barangay 10']);
        await barangay.selectOption('1380601010');
        await assertClean(page, `Barangay Visit (answered, ${page.viewportSize()?.width ?? 0}px)`);

        await page.getByRole('button', { name: 'Submit' }).click();
        await expect(page.getByRole('heading', { name: 'Thanks — your response has been recorded.' })).toBeVisible({ timeout: 15_000 });
    });
}
