import { test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// Composed-page responsive + accessibility gate. Each authenticated tenant page is scanned at the
// three reference viewports (the config's projects) in light AND dark for zero WCAG 2.2 AA violations,
// and asserted to never scroll horizontally (Feature #5's responsive contract). This is integration
// coverage the per-component Storybook axe run cannot provide (heading order, landmark structure, and
// real text-on-surface contrast only exist once a page is assembled). The assertClean/forceTheme helpers
// are shared with the guest public-runtime scan (tests/e2e/public-runtime-axe.spec.ts).

const pages = [
    { name: 'Dashboard', path: '/dashboard' },
    { name: 'Forms', path: '/forms' },
    { name: 'Submissions', path: '/submissions' },
    { name: 'Members', path: '/members' },
    { name: 'Settings', path: '/settings' },
];

const themes = ['light', 'dark'] as const;

for (const p of pages) {
    for (const theme of themes) {
        test(`${p.name} (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
            await page.goto(p.path, { waitUntil: 'networkidle' });
            await forceTheme(page, theme);
            await assertClean(page, p.name);
        });
    }
}

// The interactive builder (D4a). Reached via the form title link on the list (no id in the URL); the
// page auto-selects the first field on load, so the config panel + tabs are mounted for the scan. The
// full interaction-driven pass (opening dialogs, keyboard reorder + aria-live) is D4b.
for (const theme of themes) {
    test(`Builder (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Community Health Survey' }).click();
        await page.waitForURL('**/builder', { timeout: 30_000 });
        await page.getByRole('tab').first().waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Builder');
    });
}

// The manual-encoding page (F4b). Reached via the "New submission" row action of the all-scalar published
// "Clinic Intake" form (no id in the URL). A CSS `tr` locator scoped by row text is used rather than
// getByRole('row', …) because the DataTable's mobile (375px) card layout drops the table ARIA role.
// The scan covers every Phase-1 encode control (text/number/select/multi-select/yes-no/date/long-text).
for (const theme of themes) {
    test(`Encode (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Clinic Intake' })
            .getByRole('button', { name: 'New submission' })
            .click();
        await page.waitForURL('**/submissions/create', { timeout: 30_000 });
        await page.getByRole('button', { name: 'Submit response' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Encode');
    });
}

// The manual-encode REPEAT-GROUP page (Increment G2). The published "Household Roster" has a repeatable
// section (min 1), so the encode page seeds one instance fieldset on load — its add/remove-instance loop +
// member inputs are scanned at all three viewports in light + dark.
for (const theme of themes) {
    test(`Encode repeat group (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Household Roster' })
            .getByRole('button', { name: 'New submission' })
            .click();
        await page.waitForURL('**/submissions/create', { timeout: 30_000 });
        await page.getByRole('button', { name: 'Add Household members' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Encode repeat group');
    });
}

// The submission detail + reviewer workflow (F7). Reached from the inbox by opening the first row's "View
// submission" action (no id in the URL). The seeded submissions render answers + a review action bar; the
// scan covers the read-only answer blocks and the review buttons at all three viewports.
for (const theme of themes) {
    test(`Submission detail (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/submissions', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'View submission' }).first().click();
        await page.waitForURL(/\/submissions\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page.getByRole('link', { name: 'Back to submissions' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Submission detail');
    });
}
