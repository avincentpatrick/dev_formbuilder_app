import { expect, test, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * M165 (`R-7173c02c`, `D106`) — a filled paper copy sent from the public link, and the staff list that receives it.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE UPLOAD ITSELF IS ANSWERED IN THE BROWSER (`page.route`), NEVER BY THE SERVER. CI's e2e job runs
 * `QUEUE_CONNECTION=sync`, so a real upload would run the reading job inline against the real provider — the reason
 * `ocr-review-axe.spec.ts` and `ocr-scans-upload.spec.ts` never send one either. The spam check before it IS real:
 * the browser mints and solves one, which is the half of the path a mock would hide. The staff half reads the guest
 * scan `E2eSeeder::seedOcrGuestScan()` wrote, so this spec creates nothing.
 */

const themes = ['light', 'dark'] as const;

// A 1×1 PNG — enough for a real preview to decode, so axe and the overflow check see a loaded image.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

test.describe('the public link', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    async function openForm(page: Page): Promise<void> {
        await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Clinic Intake', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
    }

    for (const theme of themes) {
        test(`a filled paper copy, chosen and sent, and the thank-you with its reference (${theme})`, async ({ page }) => {
            const sent: string[] = [];
            await page.route('**/api/v1/public/f/*/scans', async (route) => {
                sent.push(route.request().headers()['x-meridian-challenge'] ?? '');
                await route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ data: { reference: 'AB12-CD34' } }) });
            });

            await openForm(page);
            await forceTheme(page, theme);

            const open = page.getByRole('button', { name: 'Filled in a paper copy? Upload it instead' });
            await expect(open).toHaveAttribute('aria-expanded', 'false');
            await open.click();
            await expect(open).toHaveAttribute('aria-expanded', 'true');

            await page.getByLabel('Pages of your paper form').setInputFiles([
                { name: 'paper-page-1.png', mimeType: 'image/png', buffer: PNG },
                { name: 'paper-page-2.png', mimeType: 'image/png', buffer: PNG },
            ]);
            await expect(page.getByRole('list', { name: '2 pages chosen' }).getByRole('listitem')).toHaveCount(2);

            await assertClean(page, `public paper copy, chosen (${theme})`);

            await page.getByRole('button', { name: 'Send paper copy' }).click();

            await expect(page.getByRole('heading', { name: 'Your paper form was received. Staff will check it and enter your answers.' })).toBeVisible({
                timeout: 30_000,
            });
            await expect(page.getByText('Reference: AB12-CD34')).toBeVisible();
            // The check was minted and solved for real, and travelled as the header the server requires.
            expect(sent).toHaveLength(1);
            expect(JSON.parse(Buffer.from(sent[0], 'base64').toString()).signature).toBeTruthy();

            await assertClean(page, `public paper copy, received (${theme})`);
        });
    }
});

test.describe('the staff list', () => {
    async function openScans(page: Page): Promise<void> {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Clinic Intake', exact: true }).first().click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });

        await page.getByRole('navigation', { name: 'Clinic Intake' }).getByRole('link', { name: 'Responses' }).click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/submissions$/, { timeout: 30_000 });

        await page.getByRole('button', { name: 'Scan paper forms' }).click();
        await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
    }

    for (const theme of themes) {
        test(`a guest's scan is marked and found by its reference however it is typed (${theme})`, async ({ page }) => {
            await openScans(page);
            await forceTheme(page, theme);

            const guestRow = page.locator('.scans__row', { hasText: 'From the public link' });
            await expect(guestRow).toHaveCount(1);
            await expect(guestRow).toContainText('Reference GQ7K-4M2X');

            const search = page.getByRole('searchbox', { name: 'Find a scan by its reference' });
            await search.fill('gq7k 4m2x');
            await search.press('Enter');
            await page.waitForURL(/reference=/, { timeout: 30_000 });

            await expect(page.locator('.scans__row')).toHaveCount(1);
            await expect(page.locator('.scans__row')).toContainText('From the public link');

            await assertClean(page, `scans page, found by reference (${theme})`);

            await page.getByRole('button', { name: 'Show all scans' }).click();
            await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
            await expect(page.locator('.scans__row', { hasText: 'Ready to review' })).toHaveCount(1);
        });
    }
});
