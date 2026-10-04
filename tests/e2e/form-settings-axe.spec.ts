import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * The form hub's Settings tab (M129, `R-1132a6f3` — the hub half of `D63`). It mounts the same sections the
 * builder's "Form settings" modal does, so each section is scanned here once in the PAGE's layout, which is
 * wider than the modal's 520px shell and shares the screen with the hub's tab strip — the two places this
 * entry point can differ from the one `builder-axe.spec.ts` already scans.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * This spec CHANGES NOTHING: it opens sections and scans them, and never toggles, saves or picks. Every
 * spec shares one seeded database across three viewport projects, so a save here would change what the next
 * project's scan sees.
 *
 * ⚠️ The tab is reached by the strip's accessible name, the FORM's title. Exactly one navigation on the page
 * carries it (the breadcrumb is "Breadcrumb"); Playwright matches that name by substring, so a second would
 * make this wait — and every hub spec's — resolve two elements.
 */

const themes = ['light', 'dark'] as const;

/** Every section the Owner of a Business workspace is offered, in rail order. */
const SECTIONS = ['Details', 'Pages', 'Theme', 'Share', 'Scanning', 'Schedule', 'Thank-you message', 'Save and finish later', 'Scope'];

async function openSettings(page: Page, formTitle: string): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: formTitle, exact: true }).first().click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });

    await page.getByRole('navigation', { name: formTitle }).getByRole('link', { name: 'Settings' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/settings$/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: 'Settings', level: 1 })).toBeVisible();
}

for (const theme of themes) {
    test(`Form settings tab — every section (${theme})`, async ({ page }) => {
        await openSettings(page, 'Clinic Intake');
        await forceTheme(page, theme);

        const rail = page.getByRole('group', { name: 'Settings section' });
        await expect(rail.getByRole('button')).toHaveText(SECTIONS);

        for (const section of SECTIONS) {
            await rail.getByRole('button', { name: section, exact: true }).click();
            await expect(rail.getByRole('button', { name: section, exact: true })).toHaveAttribute('aria-pressed', 'true');
            await assertClean(page, `form settings tab, ${section} (${theme})`);
        }
    });

    test(`Form settings tab — scanning a form paper cannot carry (${theme})`, async ({ page }) => {
        // Community Health Survey has a repeating section, so its paper cannot be read: the section says so.
        await openSettings(page, 'Community Health Survey');
        await forceTheme(page, theme);

        await page.getByRole('group', { name: 'Settings section' }).getByRole('button', { name: 'Scanning', exact: true }).click();
        await expect(page.getByText(/cannot be read automatically/)).toBeVisible();

        await assertClean(page, `form settings tab, scanning ineligible (${theme})`);
    });
}

// M130 (`R-db169c29`, `D76`) — the Thank-you section's second half, in each kind. Picking a kind is local to the
// page and is never saved, so the STATE note above still holds.
for (const theme of themes) {
    test(`Form settings tab — where a respondent goes next, in each kind (${theme})`, async ({ page }) => {
        await openSettings(page, 'Clinic Intake');
        await forceTheme(page, theme);
        await page.getByRole('group', { name: 'Settings section' }).getByRole('button', { name: 'Thank-you message', exact: true }).click();

        const next = page.getByRole('group', { name: 'After the thank-you screen' });
        await expect(next.getByRole('radio', { name: 'Stay on the thank-you screen' })).toBeChecked();

        // The label, as a person clicks it: the native input is visually hidden under the drawn circle.
        await next.getByText('Go to another form', { exact: true }).click();
        await expect(next.getByRole('radio', { name: 'Go to another form' })).toBeChecked();
        await expect(next.getByRole('combobox', { name: 'Form' })).toBeVisible();
        await assertClean(page, `form settings tab, destination: a form (${theme})`);

        // The label, as a person clicks it: the native input is visually hidden under the drawn circle.
        await next.getByText('Go to a web address', { exact: true }).click();
        await expect(next.getByRole('radio', { name: 'Go to a web address' })).toBeChecked();
        await expect(next.getByRole('textbox', { name: 'Web address' })).toBeVisible();
        await assertClean(page, `form settings tab, destination: a web address (${theme})`);
    });
}
