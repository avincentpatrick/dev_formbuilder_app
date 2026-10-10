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
 *
 * M153 adds the third screen: a response saved from a scan, which shows "Scanned pages" beside its answers.
 * E2eSeeder gives each seeded `ocr_single` response the two-page scan it was saved from (adding no response), so
 * whichever the inbox lists first carries both pages. Still nothing is created here.
 *
 * M154 adds the fourth: that response's "Edit answers" page, which keeps the paper beside the form. It is only
 * OPENED — nothing is saved, so the seeded response stays as it was for the next project's run.
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
        // The seeded sheet holds every reviewer-facing note: one to check, one filled in that may be wrong (D108), one
        // needing manual entry, a blank.
        await expect(page.getByText(/Check this answer: it was read at 81% confidence/)).toBeVisible();
        await expect(page.getByText(/This may be wrong: it was read at 52% confidence/)).toBeVisible();
        // …and its answer is IN the box, not only quoted in the note (the user's comment on the staging scan).
        await expect(page.getByRole('checkbox', { name: 'Fever', exact: true })).toBeChecked();
        await expect(page.getByRole('checkbox', { name: 'Cough', exact: true })).toBeChecked();
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

    test(`Response saved from a scan (${theme})`, async ({ page }) => {
        await page.goto('/submissions?source=ocr_single', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'View submission' }).first().click();
        await page.waitForURL(/\/submissions\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await forceTheme(page, theme);

        // The user's words on staging: "i did not see the 2 photos when i click the submitted response".
        await expect(page.getByRole('heading', { name: 'Scanned pages', level: 2 })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Page 1 of the scan' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Page 2 of the scan' })).toBeVisible();

        await assertClean(page, `scanned response ${theme}`);
    });

    test(`Correcting a response saved from a scan (${theme})`, async ({ page }) => {
        await page.goto('/submissions?source=ocr_single', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'View submission' }).first().click();
        await page.waitForURL(/\/submissions\/[0-9a-f-]{36}$/, { timeout: 30_000 });

        await page.getByRole('link', { name: 'Edit answers' }).click();
        await page.waitForURL(/\/submissions\/[0-9a-f-]{36}\/edit$/, { timeout: 30_000 });
        await forceTheme(page, theme);

        // Before M154 "Edit answers" took the paper away the moment a reviewer went to fix a misread answer.
        await expect(page.getByRole('heading', { name: 'Edit answers', level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Scanned pages', level: 2 })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Page 1 of the scan' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Page 2 of the scan' })).toBeVisible();

        await assertClean(page, `correcting a scanned response ${theme}`);
    });
}
