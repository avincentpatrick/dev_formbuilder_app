import { expect, test } from '@playwright/test';
import { centralOrigin as sharedCentralOrigin } from './support/hosts';
import { assertClean, forceTheme } from './support/axe';
import { formEntry, openBuilder, showBuilderPane } from './support/navigate';

// Composed-page responsive + accessibility gate. Each authenticated tenant page is scanned at the
// three reference viewports (the config's projects) in light AND dark for zero WCAG 2.2 AA violations,
// and asserted to never scroll horizontally (Feature #5's responsive contract). This is integration
// coverage the per-component Storybook axe run cannot provide (heading order, landmark structure, and
// real text-on-surface contrast only exist once a page is assembled). The assertClean/forceTheme helpers
// are shared with the guest public-runtime scan (tests/e2e/public-runtime-axe.spec.ts).
//
// ⛔⛔ READ THIS BEFORE TRUSTING ANY "the overflow assertion protects X" COMMENT BELOW — AND THERE ARE
// MANY OF THEM. Between 2026-07-21 and M17 (2026-08-26) the horizontal-overflow assertion was
// STRUCTURALLY INCAPABLE OF FAILING on every page in this file. `AppLayout.vue` sets
// `.app-shell { overflow-x: clip }` (landed in G11, `506ff97`), `clip` mints no scroll container, and
// the assertion read `documentElement.scrollWidth` — which therefore never moved, whatever the page
// did. M17 measured it: deleting the load-bearing wrap guard from `/domains`' 64-hex DNS token put
// 312px of real overflow on the page, and BOTH cases below passed, in both themes, on a test whose
// name ends "no horizontal overflow".
//
// ⚠️ THE COMMENTS WERE NOT MERELY OPTIMISTIC, THEY MISATTRIBUTED REAL CATCHES. Two below claimed
// specific historical saves — "has now caught Domains and the Audit log", and "has reddened this gate
// three times (H12b, H14, H15b)". The clip predates all three of those increments by six days, so
// whatever went red on them, it was not this assertion; the likeliest culprit is an axe violation on
// the same page, credited to the neighbouring check. Both are corrected in place rather than deleted,
// because the misattribution is the more useful record: **it is how a gate comes to be trusted.**
//
// `assertClean` now measures `.app-shell__content` as well as the document — see the docblock on
// `assertNoHorizontalOverflow` in support/axe.ts for which box catches what, and for the two things it
// still cannot see (a top-nav overrun, and an element that is its own scroll container).

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
    // The achievements surface (K1e). ⚠️ THIS IS THE ONLY GATE THAT CAN SEE MOST OF THIS PAGE, and it has
    // more to catch here than the neighbours: two `auto-fit` tile grids, a badge grid whose cards carry a
    // meter, and a leaderboard row of four flex children with a name that must ellipsis rather than push
    // the row wide. The horizontal-overflow assertion at 375px is the one that bites — the same failure
    // this gate has already caught three times on other pages.
    //
    // ⚠️ AND IT ONLY LOADS BECAUSE `gamification` IS GRANTED ON EVERY TIER (ADR-0020 §D6) and the module
    // toggle defaults ON, so no seeder obligation attaches the way ADR-0011 §D9 puts one on /analytics.
    // The seeded acme owner holds `dashboard.org.view`, so the scan covers the GATED half — the ladder and
    // the workspace totals — which is the half with the more complicated markup.
    { name: 'Achievements', path: '/achievements' },
    { name: 'Forms', path: '/forms' },
    // The forms list's SECOND view (JR3). Without this entry the enriched seven-column table, the
    // single-line action cluster, the two `align: 'end'` columns and `MdsDataTable`'s own scroll region
    // are scanned by nothing at all — `/forms` above renders the card grid now, so the table stopped
    // being covered the moment the default flipped. ⚠️ JR4 CHANGED WHAT THIS ENTRY SEES AT TWO OF THE
    // THREE VIEWPORTS: the collapse is keyed on the container now, so this table renders as CARDS below
    // 56em of container (375 and 834) and as the dense seven-column table only at 1440. The mode that can
    // still scroll sideways is therefore the desktop one, between ~896px and the ~1136px it needs.
    { name: 'Forms (table view)', path: '/forms?view=table' },
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
    // Custom domains (H22b) — Business-gated, reachable because E2eSeeder upserts acme onto a Business
    // plan. Two seeded cards: one pending, one verified-and-awaiting-the-operator.
    //
    // THIS PAGE IS AN OVERFLOW TRAP AND THAT IS WHY IT IS HERE. Every card carries a 64-hex verification
    // token and a fully-qualified challenge name, neither of which has a natural break opportunity — at
    // 375px they are the widest unbreakable strings anywhere in the authenticated app. The DNS block's
    // `minmax(0, 1fr)` column and `overflow-wrap: anywhere` are what keep the body from scrolling
    // sideways, and this scan is the only thing that checks they still do.
    { name: 'Domains', path: '/domains' },
    // The audit ledger (I2, PRD Feature #12) — Owner/Admin only and, unlike its four neighbours above, NOT
    // plan-gated: PlanCatalog carries no audit key on any tier because accountability is baseline. Reachable
    // because the seeded demo user is the tenant Owner.
    //
    // ⚠️ THIS PAGE IS ALREADY NON-EMPTY WITHOUT A FIXTURE. PublishService, WebhookEndpointService and
    // ResourceGrantService all audit inside E2eSeeder's own transaction, and since I2 so does every
    // FormService write. What `E2eSeeder::seedAuditLog()` buys is the SPREAD: without it there is no
    // `deleted`, `restored`, `archived` or `exported` row anywhere in the seeded data and no diff carries a
    // redacted field, so this scan would be green over three badge variants and a redaction notice that
    // never rendered.
    //
    // It also plants the widest unbreakable strings on the page — a full uuid and a long webhook URL inside
    // a diff cell — which is the Domains overflow trap arriving on a second surface.
    { name: 'Audit log', path: '/audit-log' },
    // I5 added four cards here (Access · Maintenance · Modules · About) and swapped fifteen controls from
    // MdsCheckbox to MdsSwitch, so this entry now scans the whole App Settings surface for free at three
    // viewports × two themes. The E2E demo user is the Owner and E2eSeeder puts acme on Business, so
    // `can_manage` is true and the Modules list renders populated rather than empty.
    //
    // The PLATFORM half (/admin/settings) is not here because it is not a TENANT page — it lives on the
    // central host, behind a super-admin session this file's storageState is not. It IS scanned now, in
    // `admin-console-axe.spec.ts` (I10e), which carries its own session; the TOTP the old note blamed
    // turned out not to be needed at all.
    { name: 'Settings', path: '/settings' },
    // The workspace's own feedback (I7a, PRD Feature #11). `E2eSeeder::seedFeedback()` puts one row in
    // each of the four FeedbackStatus states, which is what makes this scan worth running: the four map
    // to four DIFFERENT badge variants, and a table where every pill is the same colour proves nothing
    // about the other three. The last fixture row also carries a long unbroken URL inside the remarks
    // cell — the 375px overflow trap. ⚠️ This comment used to claim the trap "has now caught Domains
    // and the Audit log". It cannot have: `.app-shell` has been `overflow-x: clip` since G11, so the
    // document-width assertion could not fail on any page in this file until M17. Something on those
    // pages did go red — but it was an axe violation, not this check. The fixture is still worth
    // having, and NOW the assertion behind it can actually fail.
    //
    // The PLATFORM console (/admin/feedback) is on the same footing as /admin/settings: central host,
    // super-admin session, so it is scanned by `admin-console-axe.spec.ts` (I10e) rather than here.
    { name: 'Feedback', path: '/feedback' },
    // The step-up re-authentication page (I8c, scanning what I8a made load-bearing). It sits behind
    // `auth`, which is why it is HERE and not in auth-axe.spec.ts with the genuinely unauthenticated
    // pages — a no-storageState visit would be redirected to /login and would scan that instead, passing
    // while covering nothing.
    //
    // It earns a scan because I8a changed what it is. Before, it guarded nothing and almost nobody saw
    // it; now it is the interposed step for ownership transfer, member role changes, member removal and
    // every page of the super-admin console — a page an administrator meets in the middle of a task they
    // have already started, which is precisely when an accessibility failure is most costly.
    { name: 'Confirm password', path: '/user/confirm-password' },
    // J3b — the 2FA enrolment interstitial, which until now was scanned by NOTHING despite embedding the
    // widest content in the auth family: the shared `TwoFactorSetup` panel, a QR code and an eight-item
    // recovery-code list inside a ~400px card. It belongs here rather than in `auth-axe.spec.ts` for the
    // same reason Confirm password does — it sits behind `auth`, and a session-less visit would be
    // redirected to /login and would scan that instead, passing while covering nothing.
    //
    // No fixture is required and that is worth stating, because the row that asked for this page assumed
    // one was: `TwoFactorRequiredController` renders for ANY authenticated user whose
    // `two_factor_confirmed_at` is null, and redirects away only once it is set. The route carries no
    // enforcement gate — `EnforceTenantTwoFactor` is what sends people here, not what guards it — so the
    // workspace's `security.require_two_factor` setting is irrelevant to reaching it, and the seeded demo
    // owner has never enrolled.
    { name: 'Two-factor required', path: '/two-factor/required' },
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

// ── The FILTERED-TO-ZERO state (J1e) ──────────────────────────────────────────────────────────────────
//
// ⚠️ EVERY SCAN ABOVE RUNS AGAINST A SEEDED DATABASE, SO NONE OF THEM HAS EVER RENDERED AN EMPTY STATE ON
// A LIST PAGE. That is precisely the combination J1e's `MdsFilterBar` heading protects: `PageHeader`
// renders the `<h1>` and `MdsEmptyState` renders an `<h3>`, with a populated `MdsDataTable` contributing no
// heading at all — so `heading-order` can only fail once the table is empty. A `?q` matching nothing is now
// the easiest way to reach that state, and the only one available to a scan that must not mutate data.
//
// Three pages rather than six: forms, members and webhooks are the three that had NO filter surface before
// this increment, so their `<h2>`, their empty-state branch and their `no_matches` copy are all new here.
// The other three shipped a filter bar in I2/I7a and their empty states were already reachable.
const filteredToZero = [
    { name: 'Forms', path: '/forms?q=zzzznothingmatchesthis' },
    { name: 'Members', path: '/members?q=zzzznothingmatchesthis' },
    { name: 'Webhooks', path: '/webhooks?q=zzzznothingmatchesthis' },
];

for (const p of filteredToZero) {
    for (const theme of themes) {
        test(`${p.name} filtered to zero (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
            await page.goto(p.path, { waitUntil: 'networkidle' });
            await forceTheme(page, theme);

            // The state has to be the one we think it is, or this becomes six passing scans of a populated
            // table. `No matching` is the shared prefix of all three empty-state headlines.
            await expect(page.getByRole('heading', { name: /No matching/i })).toBeVisible();

            await assertClean(page, `${p.name} filtered to zero`);
        });
    }
}

// The interactive builder (D4a). Reached by clicking through the list and then the HUB's Builder tab (no id
// in the URL) — see `support/navigate`, which owns that two-step walk for all six specs that need it; the
// page auto-selects the first field on load, so the config panel + tabs are MOUNTED for the scan at every
// width, and (since JR5) ON SCREEN below 60em of the builder's own container only when the Settings pane
// is the selected one. The full interaction-driven pass (opening dialogs, keyboard reorder + aria-live)
// is D4b, which also walks all three panes so the narrow projects keep scanning the palette and canvas.
for (const theme of themes) {
    test(`Builder (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await openBuilder(page, 'Community Health Survey');
        await showBuilderPane(page, 'settings');
        await forceTheme(page, theme);
        await assertClean(page, 'Builder');
    });
}

// Per-form response statistics (I10c) — the UNGATED Form-Owner view of docs/PRD.md:198, and the second
// analytics surface in the product. Reached from the /forms row action, so no uuid appears in this file
// (the Builder / Encode / Submission-detail precedent above).
//
// "Community Health Survey" is the fixture that makes this scan worth running rather than a formality:
// E2eSeeder::analyticsFixtureRows() gives it NINE countable responses across THREE channels (guest,
// ocr_single, api_import) plus one unconverted draft, all inside the rolling 29-day window — so the trend
// line, the channel bars, the paired data table AND both draft tiles render populated rather than as empty
// states. A single-channel form would leave the bar chart one full-width bar and the horizontal-overflow
// assertion with nothing to bite on. "OCR (single)" and "API import" are also the longest category labels
// the chart can produce, which is exactly the 375px label-wrap case that assertion exists for.
for (const theme of themes) {
    test(`Form analytics (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        // `formEntry` rather than a `tr` locator (JR3): `/forms` renders a CARD GRID by default now, so a
        // `tr` matches nothing here unless `?view=table` is asked for. The helper matches either view —
        // and still cannot use getByRole('row'), for the reason the webhook and encode blocks below
        // record: MdsDataTable drops the table ARIA role for its card layout, which since JR4 appears at any
        // width where the table's container is under 56em — the 834px project as well as 375px.
        await formEntry(page, 'Community Health Survey')
            .getByRole('button', { name: 'Response statistics' })
            .click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/analytics$/, { timeout: 30_000 });
        // J2b replaced this page's hand-rolled "← Forms" link with `MdsBreadcrumb` + `MdsTabNav`, so the old
        // `getByRole('link', { name: '← Forms' })` settle locator no longer matches anything. Waiting on the
        // STRIP rather than a crumb is the better choice anyway: it is the last thing this page renders and
        // it only appears once the server's tab set has arrived.
        await page.getByRole('navigation', { name: 'Community Health Survey' })
            .waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Form analytics');
    });
}

// The form hub (J2b) — `/forms/{form}`, the page every other surface starts linking to in J2d.
//
// Reached by CLICKING rather than by a literal path, the house rule for any uuid route: the id is minted by
// the seeder, so a hard-coded URL would be a fixture guess. The row TITLE is the entry point, which is
// itself part of what this increment changed — it used to link to the builder and only for a role that
// could edit.
//
// ⚠️ THE SCAN IS WORTH RUNNING BECAUSE OF WHAT "Community Health Survey" HOLDS: nine countable responses
// across three channels plus a draft, so the recent-responses panel is populated and the four stat tiles
// carry real values rather than em dashes. The EMPTY case — where both panels render `MdsEmptyState` under
// their h2, and `heading-order` is at stake — is covered in `show.test.ts`, which can construct a form with
// no responses at all; no seeded fixture here can, because the seeder exists to populate them.
for (const theme of themes) {
    test(`Form hub (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await formEntry(page, 'Community Health Survey')
            .getByRole('link', { name: 'Community Health Survey' })
            .click();
        // No trailing segment: the hub IS `/forms/{uuid}`, so the anchor matters or this matches the
        // builder and the analytics page too.
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page.getByRole('navigation', { name: 'Community Health Survey' })
            .waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Form hub');
    });
}

// One form's responses (J2c) — `/forms/{form}/submissions`, the hub's Responses tab.
//
// ⚠️ SCANNED SEPARATELY FROM `/submissions` EVEN THOUGH IT IS THE SAME COMPONENT, and that is the point of
// having it here: the two modes render a DIFFERENT composition. This one adds a breadcrumb trail and a tab
// strip above the filter bar and drops a table column, so it is the only mode where the page carries two
// navigation landmarks and two `aria-current="page"` elements at once — precisely the shape axe's
// `landmark-unique` exists to catch, and one a scan of the global inbox can never reach.
//
// Reached by clicking the hub's Responses TAB rather than by a literal path: the id is minted by the seeder
// (the uuid-route house rule), and walking the strip is also what proves the tab J2c repointed actually
// arrives somewhere at every viewport, including 375px where `MdsTabNav` scrolls horizontally.
for (const theme of themes) {
    test(`Form responses (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await formEntry(page, 'Community Health Survey')
            .getByRole('link', { name: 'Community Health Survey' })
            .click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page
            .getByRole('navigation', { name: 'Community Health Survey' })
            .getByRole('link', { name: 'Responses' })
            .click();
        // Anchored on `/submissions`: without the `$` this also matches `/submissions/create` and
        // `/submissions/export`, both of which live under the same prefix.
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/submissions$/, { timeout: 30_000 });
        // Settle on the strip — the last thing the page renders, and it only appears once the server's tab
        // set has arrived.
        await page.getByRole('navigation', { name: 'Community Health Survey' })
            .waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Form responses');
    });
}

// The builder's LOGIC view (H21d1) — the read-derived branching rail, scanned at all three viewports in
// light + dark. The 375px pass is the one that matters and is Doc #27 §9's explicit obligation for this
// row: an author's own expression is the widest thing on the page and it must WRAP, never scroll, so the
// `assertClean` horizontal-overflow check is doing real work here rather than being a formality.
for (const theme of themes) {
    test(`Builder logic view (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await openBuilder(page, 'Logic Notices Demo');
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        // The server-derived notice, so the widest state of the card is on screen when the scan runs.
        await page.getByText(/comes later in the form/).waitFor({ state: 'visible', timeout: 15_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Builder logic view');
    });
}

// The structured CONDITION EDITOR (H21d2) in its widest state — a nested group, whose rows carry five
// controls each and whose nesting adds an indent rule per level. Below 60em of the builder's own container
// the config pane is the ONLY pane on screen and takes the full width — JR5's pane switcher, not the
// ≤1024px linearization this comment used to describe; nothing stacks any more, and the pane has to be
// SELECTED before anything inside it can be clicked. The rows stack to one control per line below 640px
// and become a wrapping flex row above it, and both sides are still covered by the three-viewport matrix.
// The overflow assertion is the point, and this is the deepest control nesting the builder has.
// ⚠️ This comment used to add "a non-wrapping row in a shared primitive has reddened this gate three
// times (H12b, H14, H15b)". Checked in M17 and it is FALSE: the clip landed 2026-07-21 (`506ff97`)
// and all three of those merged on 07-26/07-27, so the document-width assertion was already inert
// when they ran. They did go red — on axe violations, credited to the wrong neighbour. **A comment
// citing three specific saves is exactly what stops the next reader from testing the claim.**
for (const theme of themes) {
    test(`Builder condition editor (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await openBuilder(page, 'Logic Notices Demo');
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        await page.locator('button.rail__head', { hasText: 'Grouped gate' }).click();
        await showBuilderPane(page, 'settings');
        await page.locator('[role="tab"]', { hasText: 'Advanced' }).click();
        await page.getByLabel('Condition 1.1 subject').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Builder condition editor');
    });
}

// The manual-encoding page (F4b). Reached via the "New submission" row action of the all-scalar published
// "Clinic Intake" form (no id in the URL). A CSS `tr` locator scoped by row text is used rather than
// getByRole('row', …) because the DataTable's card layout drops the table ARIA role — and since JR4 that
// layout appears wherever the table's CONTAINER is under 56em, not only at 375px.
// The scan covers every Phase-1 encode control (text/number/select/multi-select/yes-no/date/long-text).
for (const theme of themes) {
    test(`Encode (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await formEntry(page, 'Clinic Intake')
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
        await formEntry(page, 'Household Roster')
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
        await formEntry(page, 'Branching Router')
            .getByRole('button', { name: 'New submission' })
            .click();
        await page.waitForURL('**/submissions/create', { timeout: 30_000 });
        await page.getByText('No questions to answer').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Encode branching (terminal)');
    });

    test(`Encode branching — routed (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await formEntry(page, 'Branching Router')
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
        // J2c replaced this page's hand-rolled `← Back to submissions` link — its ONLY navigation, and one
        // that went to the global inbox while the h1 printed the form's title unlinked — with a four-crumb
        // `MdsBreadcrumb` in `PageHeader`'s slot, so the old settle locator matches nothing. This is the
        // SECOND time this exact substitution has broken a settle locator (J2b did it to `← Forms` on the
        // analytics page, twenty lines up), and both times CI was the only gate that could see it: the e2e
        // suite does not run locally. **Grep `tests/e2e/` for a string you are deleting from a page.**
        //
        // Waiting on the trail's landmark rather than on any one crumb's text: `MdsBreadcrumb` names it
        // "Breadcrumb" by default, it is rendered from a server-provided payload, and it does not change
        // when the trail's depth or labels do — which is what a settle signal should be.
        await page
            .getByRole('navigation', { name: 'Breadcrumb' })
            .waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Submission detail');
    });
}

// The webhook endpoint detail + delivery log (H14). Reached from the /webhooks list by opening the seeded
// "Zapier" endpoint's "View endpoint" action (no id in the URL). A CSS `tr` locator scoped by row text is used
// rather than getByRole('row', …) because the DataTable's card layout drops the table ARIA role, at any
// width where the table's container is under 56em (JR4) rather than only at 375px.
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
        await page.getByRole('navigation', { name: 'Breadcrumb' }).waitFor({ state: 'visible', timeout: 10_000 });
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
        await page.getByRole('navigation', { name: 'Breadcrumb' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Integration rule detail');
    });
}

// The Sheets rule detail with its DRIFT card (H16b). Its own loop rather than a widened one above, because
// the markup under scan genuinely differs: a paused Sheets rule mounts a warning-tinted reason panel and a
// two-button action row the Slack rule never renders, and that panel is the only place on this page where
// text sits on `status-warning-bg` — in both themes, which is exactly what the theme loop is for.
//
// Filtered on "responses sheet", which is unique. "Clinic Intake" alone would ALSO match the seeded Slack
// rule "Clinic Intake → #clinic" and open whichever row happened to sort first.
//
// Deliberately does not open the rule modal, for the reason above and one more specific to this provider:
// opening it calls the sheet sidecars, which reach Google. e2e has no credentials and no business making
// that request.
for (const theme of themes) {
    test(`Sheets rule detail — drift (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/integrations', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'responses sheet' })
            .getByRole('button', { name: 'View rule' })
            .click();
        await page.waitForURL(/\/integrations\/rules\/[0-9a-f-]{36}$/, { timeout: 30_000 });
        await page.getByRole('navigation', { name: 'Breadcrumb' }).waitFor({ state: 'visible', timeout: 10_000 });
        // The card this increment exists for. Waiting on it rather than assuming it means a seeder change
        // that dropped the blocked delivery fails HERE, loudly, instead of quietly scanning a page without it.
        await page.getByRole('button', { name: 'Review columns' }).waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Sheets rule detail — drift');
    });
}

// The audit change-detail dialog (I2) — this increment's ONLY new structural markup: a before/after
// <table> with a visually-hidden <caption> and column headers, inside a dialog that becomes a FULL-BLEED
// SHEET at 375px (Modal's ≤480px rule). That is where it earns a scan of its own: the diff carries a full
// uuid and a long URL value, and the table's own ≤480px stacked layout is hand-written in the component
// rather than inherited from MdsDataTable, so nothing else in this suite covers it.
//
// A CSS `tr` locator scoped by row text rather than getByRole('row', …), for the same reason the webhook
// detail above uses one: the DataTable's card layout drops the table ARIA role, at every width where its
// container is under 56em rather than only at 375px (JR4). `forceTheme` runs
// AFTER the click, matching that test.
for (const theme of themes) {
    test(`Audit change detail (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/audit-log', { waitUntil: 'networkidle' });
        await page
            .locator('tr')
            .filter({ hasText: 'Permission changed' })
            .getByRole('button', { name: 'View changes' })
            .first()
            .click();
        await page.getByRole('dialog').waitFor({ state: 'visible', timeout: 10_000 });
        await forceTheme(page, theme);
        await assertClean(page, 'Audit change detail');
    });
}

// The notification centre (I4) — the only interactive overlay in the suite anchored to a 40px trigger in
// the top nav rather than centred by MdsModal, and the only one that appears on EVERY page (the bell is
// shell chrome, so its trigger is already inside all thirteen scans above; this one opens it).
//
// ⚠️ THE FEED IS STUBBED, AND THAT IS NOT A SHORTCUT. The bell polls on a ~60s interval, `artisan serve`
// is single-process (workers: 1) and CI retries once — so a tick landing between forceTheme and
// assertClean would re-render the list mid-scan and produce an intermittent red that "passes on re-run",
// the worst kind. Fulfilling the route BEFORE goto also makes the rows deterministic, which is what lets
// this assert on specific states rather than on whatever the seeder happened to write.
//
// ⚠️ THE BOUNDING-BOX CHECK IS LOAD-BEARING AND assertClean CANNOT REPLACE IT. `.app-shell` is
// `overflow-x: clip`, so a popover that runs off the left edge at 375px is CLIPPED rather than scrolled —
// the scrollWidth assertion stays green over an unreadable panel. This is the only thing that proves the
// ≤480px fixed-sheet rule in NotificationBell.vue is still doing its job.
//
// The trigger is matched by a PREFIX regex because its accessible name carries the unread count
// ("Notifications, 3 unread") — a WCAG 1.4.1 requirement, and the reason an exact-name locator would rot.
const notificationFeed = {
    unread_count: 3,
    items: [
        {
            id: '0192e2e0-0000-7000-8000-0000000000f1',
            type: 'submission_received',
            title: 'New submission',
            description: 'A new response arrived on Clinic Intake.',
            url: '/submissions/0192e2e0-0000-7000-8000-0000000000a1',
            action_label: 'View submission',
            read_at: null,
            created_at: new Date(Date.now() - 6 * 60_000).toISOString(),
        },
        {
            // The danger chip, and a long unbroken title — the overflow-wrap case at 375px.
            id: '0192e2e0-0000-7000-8000-0000000000f2',
            type: 'webhook_failed',
            title: 'Webhook paused',
            description: 'CRM-sync-production-endpoint-with-a-deliberately-long-name stopped accepting deliveries.',
            url: '/webhooks/0192e2e0-0000-7000-8000-0000000000b1',
            action_label: 'View webhook',
            read_at: null,
            created_at: new Date(Date.now() - 3 * 86_400_000).toISOString(),
        },
        {
            // url: null — the NON-INTERACTIVE row. Renders as text with no action label, and is the only
            // fixture that would catch an `<a href="">` regression.
            id: '0192e2e0-0000-7000-8000-0000000000f3',
            type: 'export_ready',
            title: 'Export failed',
            description: 'Your export for Clinic Intake could not be generated.',
            url: null,
            action_label: 'View submission',
            read_at: null,
            created_at: new Date(Date.now() - 4 * 86_400_000).toISOString(),
        },
        {
            // A READ row: dimmer title weight, no unread dot, no mark-read button, and the spacer that
            // keeps the grid columns aligned against the three above it.
            id: '0192e2e0-0000-7000-8000-0000000000f4',
            type: 'member_invited',
            title: 'Member invited',
            description: 'pending@meridian.test was invited as viewer.',
            url: '/members',
            action_label: 'View members',
            read_at: new Date(Date.now() - 2 * 86_400_000).toISOString(),
            created_at: new Date(Date.now() - 2 * 86_400_000).toISOString(),
        },
    ],
};

for (const theme of themes) {
    test(`Notification centre (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.route('**/notifications', (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(notificationFeed) }),
        );

        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /^Notifications/ }).click();

        const panel = page.getByRole('dialog', { name: 'Notifications' });
        await panel.waitFor({ state: 'visible', timeout: 10_000 });
        await page.getByRole('listitem').first().waitFor({ state: 'visible', timeout: 10_000 });

        const box = await panel.boundingBox();
        expect(box, 'the notification panel has no box').not.toBeNull();
        expect(box!.x, 'the notification panel starts off the left edge').toBeGreaterThanOrEqual(0);

        await forceTheme(page, theme);
        await assertClean(page, 'Notification centre');
    });
}

// ── The platform landing page (I6), and the FIRST central-domain page in this matrix ────────────────────
// The standing exclusion recorded in playwright.config.ts is specifically about `superadmin.mfa` needing a
// TOTP in CI, which is why /admin/* is covered by a Storybook story instead. `/` has no MFA requirement and
// no auth requirement at all, so it joins the real-browser scan on its own merits — and it needs to, being
// a page built on the marketing-scale type tokens. (That clause used to read "the one page in the product
// built on the marketing-scale type and space tokens that appear nowhere else" — false twice over, and
// corrected in J3b: `--mds-space-16` was always used by the top nav and the notification panel, and the
// display role now has a second consumer in the split auth panel. See exceptions-log #8 and #14.)
//
// ⚠️ ABSOLUTE URL, DELIBERATELY. playwright.config.ts's `baseURL` is the TENANT host, so a relative
// page.goto('/') lands on the workspace root — which since I6 redirects to /dashboard. That redirect is
// worth asserting too, and the second block below does exactly that.
// The shared derivation (see `support/hosts.ts`), not the third hand-rolled copy of the same transform.
const centralOrigin = sharedCentralOrigin;

for (const theme of themes) {
    test(`Platform landing (${theme}) — accessible & no horizontal overflow`, async ({ browser }) => {
        // A FRESH context with no storageState: the landing page's whole job is to greet someone who is not
        // signed in. The saved session's cookies are host-only and would not be sent to the central host
        // anyway, but relying on that silently is how a test comes to assert the wrong page.
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();

        try {
            await page.goto(centralOrigin + '/', { waitUntil: 'networkidle' });
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await forceTheme(page, theme);
            await assertClean(page, 'Platform landing');
        } finally {
            await context.close();
        }
    });
}

test('the workspace root sends a signed-in member to the dashboard', async ({ page }) => {
    // The other half of I6's single-route branch, asserted against a real browser on the tenant host that
    // playwright's baseURL already points at.
    await page.goto('/', { waitUntil: 'networkidle' });
    await expect(page).toHaveURL(/\/dashboard$/);
});
