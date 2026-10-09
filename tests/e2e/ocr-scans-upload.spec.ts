import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * M154 (`R-c84e4f12`) — the scans page lists the chosen pages before "Read this scan": a preview, the name, the
 * size and a Remove for each, so a wrong photo is caught before it is sent rather than on the review screen.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * This spec CREATES NOTHING and ⛔ NEVER CLICKS "Read this scan": CI's e2e job runs `QUEUE_CONNECTION=sync`, so
 * an upload would run the reading job inline against the real provider (`ocr-review-axe.spec.ts` says the same).
 * Choosing files is client-only — the photos are built in memory and never leave the browser.
 *
 * Its own file rather than a case in `ocr-review-axe.spec.ts`, which `M154`'s edit-page row already edits: one
 * file, one row (`D13`).
 */

const themes = ['light', 'dark'] as const;

// A 1×1 PNG — enough for a real preview to decode, so axe and the overflow check see a loaded image.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function openScans(page: Page): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Clinic Intake', exact: true }).first().click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });

    await page.getByRole('navigation', { name: 'Clinic Intake' }).getByRole('link', { name: 'Responses' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/submissions$/, { timeout: 30_000 });

    await page.getByRole('button', { name: 'Scan paper forms' }).click();
    await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: 'Scanned forms', level: 1 })).toBeVisible();
}

for (const theme of themes) {
    test(`Scanned forms page — the chosen pages before reading (${theme})`, async ({ page }) => {
        await openScans(page);
        await forceTheme(page, theme);

        const input = page.getByLabel('Pages of one form');
        await input.setInputFiles([
            { name: 'page-1-of-the-form.png', mimeType: 'image/png', buffer: PNG },
            { name: 'a-photo-taken-by-mistake.png', mimeType: 'image/png', buffer: PNG },
        ]);
        // A second pick ADDS — the page a person forgot is picked on its own.
        await input.setInputFiles([{ name: 'page-2-of-the-form.png', mimeType: 'image/png', buffer: PNG }]);

        const chosen = page.getByRole('list', { name: '3 pages chosen' });
        await expect(chosen.getByRole('listitem')).toHaveCount(3);
        await expect(chosen.getByText('a-photo-taken-by-mistake.png')).toBeVisible();

        await assertClean(page, `scans page with chosen pages ${theme}`);

        await page.getByRole('button', { name: 'Remove a-photo-taken-by-mistake.png' }).click();

        const left = page.getByRole('list', { name: '2 pages chosen' });
        await expect(left.getByRole('listitem')).toHaveCount(2);
        // Scoped to the list: the live region still says "a-photo-taken-by-mistake.png removed."
        await expect(left.getByText('a-photo-taken-by-mistake.png')).toHaveCount(0);
        // Nothing was sent: the scans list still holds only the seeded scan.
        await expect(page.getByText('Ready to review')).toBeVisible();
    });
}
