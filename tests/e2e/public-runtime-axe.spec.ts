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

// The save-and-resume UI (Increment H10). Clinic Intake opts into save-and-resume (E2eSeeder), so the multi-step
// runtime renders a "Save and finish later" control; opening it upserts a server draft and shows a resume link in
// a modal. Scan the open dialog for WCAG 2.2 AA + no horizontal overflow in light and dark.
for (const theme of themes) {
    test(`Public runtime save-for-later dialog (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { name: 'Clinic Intake', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);

        await page.getByRole('button', { name: 'Save and finish later' }).click();
        // The dialog saves the draft then shows the copyable resume link — wait for the ready state.
        await page.getByRole('button', { name: 'Copy link' }).waitFor({ state: 'visible', timeout: 15_000 });
        await assertClean(page, 'Save for later dialog');
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

// The G4a/G4b/G5b2 field-type controls. The guest-enabled "Field Types Showcase" (E2eSeeder) renders a Likert
// rating scale (radio group), an N-level cascading (dependent) select, a Likert MATRIX (a radio group per
// row over a shared scale), a MATRIX (a per-cell single-select), and the geo controls (geopoint/geotrace/
// geoshape — labelled coordinate inputs + a progressive-enhancement Leaflet map). Scan the initial state (the
// grids + the map are the reflow risk — none may force horizontal scroll at 375px), then interact with the
// cascade + both grids + a geo point and scan again, in light and dark at all three viewports.
for (const theme of themes) {
    test(`Public runtime field types (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        // Block the OSM tile network so the map degrades deterministically (no flaky external fetch); the
        // always-present manual coordinate inputs are the a11y baseline the scan asserts on regardless.
        await page.route('**/*.tile.openstreetmap.org/**', (route) => route.abort());

        await page.goto('/f/field-types', { waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { name: 'Field Types Showcase', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Field Types Showcase (initial)');

        // Choose a region → the dependent province select enables + lists NCR's children.
        await page.getByRole('combobox', { name: 'Region' }).selectOption('ncr');

        // Likert matrix: pick a score in one row (each row is a group named by its legend). `exact` avoids the
        // "Good" ⊂ "Very good" substring ambiguity across the scale radios.
        await page.getByRole('group', { name: 'Cleanliness' }).getByRole('radio', { name: 'Good', exact: true }).check();
        // Matrix: pick a cell value in one row×column (row group + column-labelled select).
        await page
            .getByRole('group', { name: 'Consultation' })
            .getByRole('combobox', { name: 'Morning', exact: true })
            .selectOption('available');

        // Geo (G5b2): type a coordinate into the geopoint's manual inputs, and add a geotrace vertex — the
        // interactive vertex list + accuracy readout must stay axe-clean.
        await page.getByRole('group', { name: 'Pin your location' }).getByRole('spinbutton', { name: 'Latitude' }).fill('14.6');
        await page.getByRole('group', { name: 'Trace your route' }).getByRole('button', { name: 'Add point' }).click();

        await assertClean(page, 'Field Types Showcase (cascade + grids + geo interacted)');
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
