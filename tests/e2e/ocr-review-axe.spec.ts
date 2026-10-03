import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * Single-form OCR's two screens (M129, groundwork 2): the scans page, and the review screen — the encode page
 * in its scan mode, with the scanned page beside the form and a note on each answer the scan needs a person
 * for. Whole-page `assertClean`, because both pages are new and the overflow check is the one place their
 * layouts meet 375px: the review screen puts the paper beside the form at wide widths and stacks it below
 * that, and the scans page carries a native file input.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * This spec CREATES NOTHING. ⛔ IT NEVER UPLOADS: CI's e2e job runs `QUEUE_CONNECTION=sync`, so an upload
 * would run the reading job inline against the real provider. E2eSeeder seeds one READ scan of Clinic Intake
 * instead, with every note state on one screen. And it never saves the scan, which would turn the next
 * project's run into a redirect to a response rather than a review.
 *
 * Reached the way a person reaches it — forms list → the form's hub → its Responses tab → "Scan paper
 * forms" — because the entry button is part of what this increment ships.
 */

const themes = ['light', 'dark'] as const;

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
    test(`Scanned forms page (${theme})`, async ({ page }) => {
        await openScans(page);
        await forceTheme(page, theme);

        await expect(page.getByLabel('Pages of one form')).toBeVisible();
        await expect(page.getByText('Ready to review')).toBeVisible();

        await assertClean(page, `scanned forms page ${theme}`);
    });

    test(`Scan review screen (${theme})`, async ({ page }) => {
        await openScans(page);
        await page.getByRole('link', { name: 'Review', exact: true }).first().click();
        await page.waitForURL(/\/ocr\/scans\/[0-9a-f-]{36}\/review$/, { timeout: 30_000 });
        await forceTheme(page, theme);

        await expect(page.getByRole('heading', { name: 'Review a scanned response', level: 1 })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Page 1 of the scan' })).toBeVisible();
        // The seeded sheet holds every reviewer-facing note: one to check, two needing manual entry, a blank.
        await expect(page.getByText(/Check this answer: it was read at 81% confidence/)).toBeVisible();
        await expect(page.getByText(/Needs manual entry/).first()).toBeVisible();

        await assertClean(page, `scan review ${theme}`);
    });

    test(`Scan review screen, zoomed in (${theme})`, async ({ page }) => {
        await openScans(page);
        await page.getByRole('link', { name: 'Review', exact: true }).first().click();
        await page.waitForURL(/\/ocr\/scans\/[0-9a-f-]{36}\/review$/, { timeout: 30_000 });
        await forceTheme(page, theme);

        // Zoomed, the frame scrolls — which is exactly when a region a keyboard cannot reach would fail.
        await page.getByRole('button', { name: 'Zoom in' }).click();
        await page.getByRole('button', { name: 'Zoom in' }).click();
        await expect(page.getByText('200%')).toBeVisible();

        await assertClean(page, `scan review zoomed ${theme}`);
    });
}
