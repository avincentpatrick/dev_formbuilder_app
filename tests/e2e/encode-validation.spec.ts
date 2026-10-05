import { expect, type Page, test } from '@playwright/test';
import { formEntry } from './support/navigate';

// The staff entry page, SUBMITTED (Increment M134, `R-b21da9f3`) — the first functional spec for it. Every other
// encode spec scans the page and never presses Submit, so the browser's own constraint validation was invisible
// to the whole suite: until M134 the encode form carried no `novalidate`, and a keyer's 2.5 in a whole-number
// question or 95 in a latitude was blocked by a browser bubble before the page or the server ever saw it — on
// the one channel that disagreed with the guest page.
//
// ⚠️ WHAT EACH CASE PROVES, MEASURED (M134) rather than assumed. The POINT case is the `novalidate` proof: with the
// attribute removed it went red at all three viewports, because the browser blocks the submit and no words appear.
// The WHOLE-NUMBER case stayed green without `novalidate` — the browser engine shows a touched question's error in
// place before any submit — so it proves the refusal reaches a keyer in words (either engine's message), not the
// attribute. The last case is the positive control.
//
// Each case creates no submission except the last, which records one on "Clinic Intake" per project — the same
// form the guest submit spec already writes to.

async function openEncode(page: Page, formTitle: string): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await formEntry(page, formTitle).getByRole('button', { name: 'New submission' }).click();
    await page.waitForURL('**/submissions/create', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Submit response' }).waitFor({ state: 'visible', timeout: 10_000 });
}

test('a whole-number question refuses 2.5 in words, not with a browser bubble', async ({ page }) => {
    await openEncode(page, 'Clinic Intake');

    await page.getByLabel('Whole number', { exact: true }).fill('2.5');
    await page.getByRole('button', { name: 'Submit response' }).click();

    await expect(page.getByText('Enter a whole number.').first()).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveURL(/\/submissions\/create/);
});

test('a point outside the globe is refused in words by the engines', async ({ page }) => {
    // The map is a progressive enhancement; the manual coordinate inputs are what a keyer types into.
    await page.route('**/*.tile.openstreetmap.org/**', (route) => route.abort());
    await openEncode(page, 'Field Types Showcase');

    const point = page.getByRole('group', { name: 'Pin your location' });
    await point.getByRole('spinbutton', { name: 'Latitude' }).fill('95');
    await point.getByRole('spinbutton', { name: 'Longitude' }).fill('120.9842');
    await page.getByRole('button', { name: 'Submit response' }).click();

    await expect(page.getByText('A coordinate is out of range (longitude ±180, latitude ±90).').first()).toBeVisible({ timeout: 15_000 });
});

test('a valid entry is recorded', async ({ page }) => {
    await openEncode(page, 'Clinic Intake');

    await page.getByLabel('Whole number', { exact: true }).fill('30');
    await page.getByRole('button', { name: 'Submit response' }).click();

    await expect(page.getByText('Submission recorded.')).toBeVisible({ timeout: 15_000 });
});
