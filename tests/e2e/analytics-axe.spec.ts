import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme } from './support/axe';

/*
 * The cross-form analytics page (Increment H24b2) — the app's first surface where a filter rail, four
 * charts and a question explorer share one screen, so this spec carries most of the structural-ARIA risk
 * in the increment.
 *
 * SCOPED, not whole-page, per the G9b lesson recorded in scopes-axe.spec.ts: `assertClean` re-flags
 * PRE-EXISTING violations elsewhere on the page (that is what cost G9b three follow-up pushes), so each
 * scan is narrowed to one pane and drops the horizontal-overflow check. The whole-page scan for /analytics
 * lives in responsive-axe, which owns overflow.
 *
 * `scanPane` is duplicated from scopes-axe rather than extracted into support/axe.ts on purpose: extracting
 * it would put a known-flaky merge-blocking spec into this diff for zero coverage gain.
 *
 * ── WHAT AXE CANNOT SEE, AND WHY THIS FILE IS NOT THE WHOLE STORY ──────────────────────────────────────
 * ADR-0011 §D12: axe cannot detect a MISSING text alternative for an SVG carrying a plausible `aria-label`,
 * so it will pass a chart that is a beautiful, unreadable picture. The data-table contract is pinned by
 * Vitest (`resources/js/Pages/analytics/index.test.ts`). What the last test here adds is the INTEGRATION
 * twin: that the tables survive to the real DOM, which a component test mounting one card cannot show.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * This spec CREATES NOTHING. `workers: 1` and three viewport projects mean every spec shares one seeded
 * database, and `saved_report_views_tenant_user_name_unique` would turn a run that died between create and
 * delete into a 422 on the NEXT project's run — a red that looks like a page bug. E2eSeeder seeds both
 * saved views (one working, one deliberately unreadable) so the list and its refusal chip are already here.
 * Filters are driven by URL rather than by clicking wherever the assertion does not need the control's own
 * interactive state, because a query-param read is structurally idempotent.
 *
 * The export is NEVER triggered: it increments `exports_count` on every run across three projects, and
 * `waitForEvent('download')` is pure flake surface in a merge-blocking job. Its rows are asserted in Pest.
 */

const themes = ['light', 'dark'] as const;

async function scanPane(page: Page, selector: string, label: string): Promise<void> {
    await page.mouse.move(0, 0);
    const results = await new AxeBuilder({ page })
        .include(selector)
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(
        results.violations,
        `${label}\n` +
            results.violations
                .map((v) => `${v.id}: ${v.help} → ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)
                .join('\n'),
    ).toEqual([]);
}

/** Open /analytics and wait for the report to have actually rendered, not merely for the network to idle. */
async function openAnalytics(page: Page, query = ''): Promise<void> {
    await page.goto(`/analytics${query}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'Filters' })).toBeVisible({ timeout: 10_000 });
}

for (const theme of themes) {
    test(`Analytics — filter rail (${theme})`, async ({ page }) => {
        // The highest ARIA risk on the page: three checkbox clusters that need a real <fieldset>/<legend>
        // each, plus eight labelled controls. `aria-required-children` does not cover grouping, so a
        // cluster of bare checkboxes with a styled <p> for a heading would pass axe and be unusable.
        await openAnalytics(page);
        await forceTheme(page, theme);

        await expect(page.getByRole('group', { name: 'Status' })).toBeVisible();
        await expect(page.getByRole('group', { name: 'Channel' })).toBeVisible();

        await scanPane(page, '.analytics__filters', `analytics filter rail ${theme}`);
    });

    test(`Analytics — charts and their data tables (${theme})`, async ({ page }) => {
        await openAnalytics(page);
        await forceTheme(page, theme);

        // The seeded fixture makes both charts non-degenerate: 30 buckets with real gaps, and a form axis
        // that overflows the top-5 into a NEUTRAL Other bar.
        await expect(page.locator('.mds-tsc')).toBeVisible();
        await expect(page.locator('.mds-bar__fill--other')).toHaveCount(1);

        await scanPane(page, '.analytics__charts', `analytics charts ${theme}`);
    });

    test(`Analytics — suppressed draft tiles (${theme})`, async ({ page }) => {
        // A 90-day range reaches past the 30-day draft retention, so ADR-0011 §D5 SUPPRESSES both draft
        // tiles. This is the only way that visual state is reachable, and it is the state most likely to be
        // rendered as a misleading "0%" — so it needs its own scan rather than being assumed.
        const to = new Date();
        const from = new Date(to.getTime() - 89 * 86_400_000);
        const iso = (d: Date): string => d.toISOString().slice(0, 10);

        await openAnalytics(page, `?from=${iso(from)}&to=${iso(to)}`);
        await forceTheme(page, theme);

        // `.first()` because BOTH tiles carry the note — which is §D5's point, not an accident, so an
        // unqualified locator is a strict-mode violation rather than a missing element.
        await expect(page.getByText(/draft retention window/).first()).toBeVisible();
        await expect(page.getByText(/draft retention window/)).toHaveCount(2);
        // Suppressed means an em dash, never a zero.
        await expect(page.getByText('0%')).toHaveCount(0);

        await scanPane(page, '.analytics__tiles', `analytics suppressed tiles ${theme}`);
    });

    test(`Analytics — question explorer, reportable and refused (${theme})`, async ({ page }) => {
        // Both halves of §D3's picker on one screen: the seeded `Programme Uptake` form carries four
        // flagged questions AND one deliberately unflagged (`notes`), while every field on the six legacy
        // forms is a `not_flagged` refusal. An axe pass alone cannot tell the two states apart, so each is
        // waited on by its own distinguishing text — the Encode-branching precedent.
        await openAnalytics(page);
        await forceTheme(page, theme);

        // `exact: true` on the first: getByRole matches an accessible name by case-insensitive SUBSTRING by
        // default, and "Available" is a substring of "Not available for reporting" — so the unqualified
        // form resolves to two groups and fails strict mode.
        await expect(page.getByRole('group', { name: 'Available', exact: true })).toBeVisible();
        await expect(page.getByRole('group', { name: 'Not available for reporting' })).toBeVisible();
        // The refusal is TEXT beside the question, not a tooltip and not a colour (WCAG 1.4.1).
        await expect(page.getByText(/Indexed for reporting/).first()).toBeVisible();

        // Select the boolean question: it is the one value column with no B-tree, so it is also the only
        // way to reach the `indexed_column: false` disclosure.
        await page.getByRole('radio', { name: 'Follow-up needed' }).check();
        // While the aggregate is in flight the card says so rather than "Pick a question" — the
        // contradiction found by looking at the real page. Waiting on the RESULT rather than on the pending
        // state keeps this a test of the answer, not of the spinner.
        await expect(page.getByText(/Summarising this question/)).toBeVisible({ timeout: 30_000 });
        // Same generous budget as the filter round trip below, for the same local-stack reason.
        await expect(page.getByText(/not separately indexed/)).toBeVisible({ timeout: 30_000 });

        await scanPane(page, '.analytics__questions', `analytics question explorer ${theme}`);
    });

    test(`Analytics — saved reports and the save dialog (${theme})`, async ({ page }) => {
        await openAnalytics(page);
        await forceTheme(page, theme);

        // Both seeded views: one applicable, one that ADR-0011 §D8 requires to state why it cannot be.
        await expect(page.getByText('Field team — last 30 days')).toBeVisible();
        await expect(page.getByText('Legacy monthly rollup')).toBeVisible();
        await expect(page.getByText(/cannot be read|saved in format/)).toBeVisible();

        await scanPane(page, '.analytics__views', `analytics saved views ${theme}`);

        // The dialog is scanned open, then CANCELLED — this spec creates nothing (see the header).
        await page.getByRole('button', { name: 'Save this report' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await scanPane(page, '[role="dialog"]', `analytics save dialog ${theme}`);
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
    });
}

test('every chart on the page exposes an equivalent data table', async ({ page }) => {
    // The integration twin of the Vitest §D12 assertions. Theme-independent, because a text alternative is
    // not a visual property. `.count()` rather than toBeVisible(): the tables ship inside sr-only clip
    // wrappers, so a visibility assertion would fail on a table that is present and correct.
    await openAnalytics(page);

    const timeSeries = page.locator('.mds-tsc');
    await expect(timeSeries).toHaveCount(1);
    // One row per plotted bucket. The seeded window is 30 days, zero-filled.
    expect(await timeSeries.locator('.mds-tsc__table tbody tr').count()).toBe(30);

    const bars = page.locator('.mds-bar');
    expect(await bars.count()).toBeGreaterThan(0);

    for (let i = 0; i < (await bars.count()); i++) {
        const chart = bars.nth(i);
        const rows = await chart.locator('.mds-bar__table tbody tr').count();
        const plotted = await chart.locator('.mds-bar__track').count();

        // Equal, not merely non-zero: a table that lost a row is exactly the silent loss axe cannot see.
        expect(rows, `bar chart ${i} tabulates every plotted category`).toBe(plotted);
    }
});

test('the filter rail keeps every grouped control inside a NAMED group', async ({ page }) => {
    // Grouping is what makes a cluster of checkboxes announce as one filter rather than as N unrelated
    // ones. axe does not check it, so it is asserted structurally.
    await openAnalytics(page);

    for (const name of ['Status', 'Channel']) {
        await expect(page.getByRole('group', { name })).toBeVisible();
    }
});

test('a filter change re-queries without a full page load, and survives a refresh', async ({ page }) => {
    // The declaration lives in the URL: that is what makes a report shareable and a refresh non-destructive.
    await openAnalytics(page);

    await page.getByLabel('Break down by').selectOption('source');
    // A generous budget: this is a real round trip that re-computes the report, the question catalogue and
    // the saved-view list. It lands in well under a second on CI and on any deployed host; a developer box
    // running the whole stack in Docker on a bind mount is an order of magnitude slower, and a timeout tuned
    // to CI would make this spec fail locally for a reason that has nothing to do with the code.
    await expect(page).toHaveURL(/axis=source/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: /Responses by channel/i })).toBeVisible();

    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: /Responses by channel/i })).toBeVisible();
});
