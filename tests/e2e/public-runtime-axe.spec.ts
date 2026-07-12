import { expect, test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// The guest public runtime (Increment F6b). A STANDALONE Vue 3 SPA (not Inertia) served at /f/{slug} on the
// tenant subdomain with NO auth, rendering the seeded, guest-enabled "Clinic Intake" form (E2eSeeder) from the
// F5 /api/v1/public schema endpoint and using the F6a engine for relevance + validation. This spec bypasses
// the shared AUTHENTICATED storageState (the guest has no session) and scans the composed page for WCAG 2.2 AA
// + no horizontal overflow at all three viewports in light and dark — the merge-blocking a11y gate for F6b.

test.use({ storageState: { cookies: [], origins: [] } });

const themes = ['light', 'dark'] as const;

for (const theme of themes) {
    test(`Public runtime (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
        // Wait for the SPA to fetch the schema and render the form heading.
        await page
            .getByRole('heading', { name: 'Clinic Intake', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Public runtime');
    });
}

// The repeat-group runtime (Increment G2). The guest-enabled "Household Roster" (E2eSeeder) has a repeatable
// section; adding an instance renders a fieldset of member inputs. Scan both the initial (empty) state and a
// populated instance for WCAG 2.2 AA + no horizontal overflow, in light and dark.
for (const theme of themes) {
    test(`Public runtime repeat group (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/f/household-roster', { waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { name: 'Household Roster', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Household Roster (empty)');

        // Add an instance → a member fieldset with inputs renders; scan again.
        await page.getByRole('button', { name: 'Add Household members' }).click();
        await page.getByRole('textbox', { name: 'Member name' }).waitFor({ state: 'visible', timeout: 10_000 });
        await assertClean(page, 'Household Roster (one instance)');
    });
}

// The G4a/G4b field-type controls. The guest-enabled "Field Types Showcase" (E2eSeeder) renders a Likert
// rating scale (radio group), an N-level cascading (dependent) select, a Likert MATRIX (a radio group per
// row over a shared scale), and a MATRIX (a per-cell single-select). Scan the initial state (the grids are
// the reflow risk — they must NOT force horizontal scroll at 375px), then interact with the cascade + both
// grids and scan again, in light and dark at all three viewports (WCAG 2.2 AA + no horizontal overflow).
for (const theme of themes) {
    test(`Public runtime field types (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/f/field-types', { waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { name: 'Field Types Showcase', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Field Types Showcase (initial)');

        // Choose a region → the dependent province select enables + lists NCR's children.
        await page.getByRole('combobox', { name: 'Region' }).selectOption('ncr');

        // Likert matrix: pick a score in one row (each row is a group named by its legend).
        await page.getByRole('group', { name: 'Cleanliness' }).getByRole('radio', { name: 'Good' }).check();
        // Matrix: pick a cell value in one row×column (row group + column-labelled select).
        await page
            .getByRole('group', { name: 'Consultation' })
            .getByRole('combobox', { name: 'Morning' })
            .selectOption('available');

        await assertClean(page, 'Field Types Showcase (cascade + grids interacted)');
    });
}

// A full guest submit (Clinic Intake's fields are all optional) drives the F5 guest submit endpoint end-to-end
// and lands on the post-submit confirmation, which is itself scanned for accessibility.
test('Public runtime — submit reaches an accessible confirmation', async ({ page }) => {
    await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    await page.getByRole('button', { name: 'Submit' }).click();

    await page
        .getByRole('heading', { name: /your response has been recorded/i })
        .waitFor({ state: 'visible', timeout: 15_000 });
    await expect(page.getByText(/Reference:/)).toBeVisible();
    await assertClean(page, 'Public runtime confirmation');
});
