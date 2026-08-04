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
    // Cross-form analytics (H24b2) — Business-gated, and reachable here only because E2eSeeder upserts
    // acme onto a Business plan (ADR-0011 §D9 names seeding that tenant as a blocking obligation, precisely
    // so this gate cannot stay green over a page it never loads).
    //
    // This is the WHOLE-PAGE scan, so it owns the horizontal-overflow assertion for the charts — and that
    // is where it bites: a non-wrapping legend row, a fixed-width SVG, or a long form title in a bar label
    // at 375px is the failure this gate has already caught three times on other pages. The filter rail's
    // eight controls and three checkbox groups are the other half of that risk.
    { name: 'Analytics', path: '/analytics' },
    { name: 'Forms', path: '/forms' },
    { name: 'Submissions', path: '/submissions' },
    { name: 'Members', path: '/members' },
    // The scoping hierarchy (G10b2). This is the whole-page scan, so it owns the horizontal-overflow
    // assertion for the tree — the deep seeded fixture exists so a 375px viewport actually reaches it.
    { name: 'Scopes', path: '/scopes' },
    // Webhook management (H14) — the list, summary tiles, Zapier recipe card, and the seeded endpoints table.
    { name: 'Webhooks', path: '/webhooks' },
    // Native connectors (H15b) — the provider catalog, two seeded workspaces (one of them in the amber
    // "Reconnect needed" state) and their rules tables. The connect button renders DISABLED here because e2e
    // has no Slack credentials, which is exactly the state a fresh deployment shows.
    { name: 'Integrations', path: '/integrations' },
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

// The builder's LOGIC view (H21d1) — the read-derived branching rail, scanned at all three viewports in
// light + dark. The 375px pass is the one that matters and is Doc #27 §9's explicit obligation for this
// row: an author's own expression is the widest thing on the page and it must WRAP, never scroll, so the
// `assertClean` horizontal-overflow check is doing real work here rather than being a formality.
for (const theme of themes) {
    test(`Builder logic view (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Logic Notices Demo' }).click();
        await page.waitForURL('**/builder', { timeout: 30_000 });
        await page.getByRole('tab').first().waitFor({ state: 'visible', timeout: 10_000 });
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        // The server-derived notice, so the widest state of the card is on screen when the scan runs.
        await page.getByText(/comes later in the form/).waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Builder logic view');
    });
}

// The structured CONDITION EDITOR (H21d2) in its widest state — a nested group, whose rows carry five
// controls each and whose nesting adds an indent rule per level. At 375px the config pane is full width
// (the builder's ≤1024px pane linearization) and the rows stack to one control per line; above 640px they
// become a wrapping flex row. Both sides of that breakpoint are covered by the three-viewport matrix, and
// the overflow assertion is the point: a non-wrapping row in a shared primitive has reddened this gate
// three times (H12b, H14, H15b), and this is the deepest control nesting the builder has.
for (const theme of themes) {
    test(`Builder condition editor (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Logic Notices Demo' }).click();
        await page.waitForURL('**/builder', { timeout: 30_000 });
        await page.getByRole('tab').first().waitFor({ state: 'visible', timeout: 10_000 });
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        await page.locator('button.rail__head', { hasText: 'Grouped gate' }).click();
        await page.locator('[role="tab"]', { hasText: 'Advanced' }).click();
        await page.getByLabel('Condition 1.1 subject').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Builder condition editor');
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

// The BRANCHING encode page (Increment H21c). The seeded "Branching Router" is Doc #27 §4.1's own example —
// a URL-prefilled `hidden` gate alone in its section, routing to two branches — and it is `single_page_mode:
// false`, so this is the only encode scan that sees the stepped flow at all.
//
// Two states, and both are new surfaces: bare, the whole graph is empty, so §4.1's TERMINAL panel renders
// with no step counter and one labelled Submit, above the keyer-only "Reference fields" block that is the
// only way to reach the gate on this channel. Keying the gate turns it into an ordinary one-step branch with
// its Back/Next row mounted. An axe pass alone would not tell the two apart, so each waits on the control
// that distinguishes it.
for (const theme of themes) {
    test(`Encode branching — terminal (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Branching Router' })
            .getByRole('button', { name: 'New submission' })
            .click();
        await page.waitForURL('**/submissions/create', { timeout: 30_000 });
        await page.getByText('No questions to answer').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Encode branching (terminal)');
    });

    test(`Encode branching — routed (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Branching Router' })
            .getByRole('button', { name: 'New submission' })
            .click();
        await page.waitForURL('**/submissions/create', { timeout: 30_000 });
        // The gate lives in the reference block — not shown to a respondent, and the keyer's only way in.
        await page.getByLabel('Role').fill('staff');
        await page.getByText('Staff details').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Encode branching (routed)');
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

// The webhook endpoint detail + delivery log (H14). Reached from the /webhooks list by opening the seeded
// "Zapier" endpoint's "View endpoint" action (no id in the URL). A CSS `tr` locator scoped by row text is used
// rather than getByRole('row', …) because the DataTable's mobile (375px) card layout drops the table ARIA role.
// The seeded endpoint carries a spread of deliveries so the action bar, detail cards, and delivery-log rows
// (every status Badge) are all mounted for the scan.
for (const theme of themes) {
    test(`Webhook detail (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/webhooks', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Zapier' })
            .getByRole('button', { name: 'View endpoint' })
            .click();
        await page.waitForURL(/\/webhooks\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page.getByRole('link', { name: 'Back to webhooks' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Webhook detail');
    });
}

// The connector rule detail + delivery log (H15b). Same shape as the webhook detail above, reached from
// /integrations by opening the seeded "New submissions → #ops" rule. Deliberately does NOT open the rule
// modal: that is the only control on this surface that calls Slack, and e2e has no credentials (nor any
// business making a live third-party request). The seeded rule carries a delivery spread so the action bar,
// both detail cards and every delivery-status Badge are mounted for the scan.
for (const theme of themes) {
    test(`Integration rule detail (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/integrations', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'New submissions' })
            .getByRole('button', { name: 'View rule' })
            .click();
        await page.waitForURL(/\/integrations\/rules\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page.getByRole('link', { name: 'Back to integrations' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Integration rule detail');
    });
}
