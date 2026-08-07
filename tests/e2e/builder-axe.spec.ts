import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme } from './support/axe';

// Interaction-driven accessibility gate for the form builder (Increment D4b) — the single highest-risk
// surface. Unlike the goto-only responsive-axe spec, this DRIVES the builder's real interactive states:
// the config panel + tabs (mounting MdsSelect/MdsTextarea/MdsNumberInput/MdsCheckbox/MdsSegmentedControl),
// the section editor, and a full keyboard reorder (grab → arrow → drop) with aria-live assertions — at all
// three viewports (the config's projects) in light AND dark. Also scans the empty-canvas state. The
// builder auto-selects a field on load so the config panel is mounted for the scan.

const themes = ['light', 'dark'] as const;

// `forceTheme` is the SHARED helper (tests/e2e/support/axe.ts), not a local copy — corrected in H21d1.
// This file had carried its own, and the difference was the whole failure: the shared one emulates
// reduced motion first, which collapses the design system's durations to 1ms centrally, and without that
// axe can sample an element MID theme-flip. A `color`-transitioned label inside a container whose
// `background-color` is not transitioned reads as the LIGHT foreground on the DARK surface — 1.82:1, a
// serious `color-contrast` violation that vanishes the moment the transition settles (the same node
// measures 6.96:1). H21d1's `Structure ⇄ Logic` control is the first always-mounted transitioning element
// in the builder's chrome, so it made a latent race in this file reproducible; the race was never its own.

/**
 * Scan the composed page, or — with `within` — only a subtree of it.
 *
 * ⚠️ `within` EXISTS FOR MODALS, AND THE REASON IS THE SCRIM. `MdsModal` renders a semi-transparent
 * backdrop and does NOT mark the rest of the page `inert`, so axe still evaluates the builder's config
 * panel underneath and BLENDS the scrim into its computed background. An unselected segmented-control
 * label that measures fine on its own surface reads 1.82:1 once axe composites the overlay on top of it —
 * a finding about content nobody can see, reach or tab to while the dialog is open.
 *
 * Caught by I8c, when the Share modal grew a "Spam protection" block: on the 375px viewport the taller
 * dialog shifted the layout enough to expose a different slice of the config panel to that blend, and the
 * whole-page scan started failing on a control the increment never touched. Same class as the G9b
 * field-library case that scoped its scan to `.builder__pane--left`.
 *
 * ⚠️ THE SELECTOR IS `[role="dialog"]`, NOT A CLASS, AND I LEARNED THAT THE EXPENSIVE WAY. My first
 * attempt used `.mds-modal`, which does not exist — the element carries `.mds-modal__panel` inside a
 * `.mds-modal__backdrop`. axe does not treat an unmatched `include` as "scan nothing"; it THROWS
 * `No elements found for include in page Context`, so a wrong selector turned two failing cases into
 * twelve. The role attribute is also the better anchor: it is the accessibility contract the test
 * already asserts on two lines above (`getByRole('dialog')`), so the scan and the wait cannot drift.
 *
 * **The process fix, which is worth more than the selector: a Playwright selector cannot be checked by
 * any local gate in this repo, so verify it against the BUILT BUNDLE before pushing** —
 * `grep -o '.\{0,90\}mds-modal__panel.\{0,90\}' public/build/assets/*.js` shows the compiled element
 * verbatim (`class:\`mds-modal__panel\`, role:\`dialog\``) and would have taken ten seconds. Guessing at
 * a class name costs a full 12-minute E2E round trip per attempt.
 *
 * ⚠️ SCOPING IS NOT A SUPPRESSION HERE, AND THE DISTINCTION MATTERS. The builder's config panel IS scanned
 * — unscrimmed, at all three viewports in both themes, by the `config panel` and `builder empty` cases in
 * this same file. What is dropped is only the second, blended look at it through an overlay. **The real
 * observation the failure surfaced — that a modal should make its background inert, for focus order and
 * screen readers, not just for axe — is filed in `docs/feature-backlog.md` rather than fixed here**, since
 * it changes every modal in the product and belongs in a design-system increment.
 */
async function scan(page: Page, label: string, within?: string): Promise<void> {
    const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows, `${label}: horizontal overflow`).toBe(false);

    const builder = new AxeBuilder({ page }).withTags([
        'wcag2a',
        'wcag2aa',
        'wcag21a',
        'wcag21aa',
        'wcag22aa',
    ]);

    const results = await (within === undefined ? builder : builder.include(within)).analyze();

    expect(
        results.violations,
        `${label}\n` +
            results.violations
                .map((v) => `${v.id}: ${v.help} → ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)
                .join('\n'),
    ).toEqual([]);
}

async function openBuilder(page: Page, formTitle: string): Promise<void> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: formTitle }).click();
    await page.waitForURL('**/builder', { timeout: 30_000 });
}

for (const theme of themes) {
    test(`Builder — populated & interactive (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Community Health Survey');
        // Auto-selected field → config panel + tabs mounted.
        await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scan(page, 'builder initial');

        // Walk the selected field's config tabs (Basics / Validation / Advanced mount the DS controls).
        const fieldTabs = page.locator('[role="tab"]');
        for (let i = 0; i < (await fieldTabs.count()); i++) {
            await fieldTabs.nth(i).click();
            await scan(page, `field tab ${i}`);
        }

        // Select the repeatable section → its Advanced tab mounts the min/max MdsNumberInputs.
        await page.getByRole('button', { name: /^New section/ }).first().click();
        const sectionTabs = page.locator('[role="tab"]');
        for (let i = 0; i < (await sectionTabs.count()); i++) {
            await sectionTabs.nth(i).click();
            await scan(page, `section tab ${i}`);
        }

        // Full keyboard reorder via the grip's grab-mode, asserting the aria-live announcements.
        const status = page.locator('.canvas__sr');
        await page.getByRole('button', { name: /^Reorder Short text/ }).focus();
        await page.keyboard.press('Enter'); // grab
        await expect(status).toContainText('Grabbed');
        await page.keyboard.press('ArrowDown'); // move (crosses within/section)
        await expect(status).toContainText(/position|Already/);
        await page.keyboard.press('Enter'); // drop
        await expect(status).toContainText('Dropped');
        await scan(page, 'after keyboard reorder');
    });

    // The Share panel (Increment I1) — scanned in BOTH of its states, because they are structurally
    // different surfaces rather than one surface with a hidden block. `Community Health Survey` is seeded
    // with no `public_slug`, so it renders the "not shareable yet" notice; `Clinic Intake` is seeded with a
    // slug AND guest access on, so it renders the live link, the QR image and the embed snippet. Scanning
    // only the first would leave every control that matters unscanned.
    //
    // Read-only on purpose: this suite shares one database with the rest of the e2e run, so toggling the
    // form's real share settings here would mutate state E2eSeederIdempotencyTest and the guest-runtime
    // specs both depend on. The two seeded rows already give both states without a write.
    test(`Builder — share panel, not yet shared (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Community Health Survey');
        await page.getByRole('button', { name: 'Share' }).click();
        await expect(page.getByRole('dialog', { name: 'Share form' })).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scan(page, 'share panel — no link yet', '[role="dialog"]');
    });

    test(`Builder — share panel, live link (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Clinic Intake');
        await page.getByRole('button', { name: 'Share' }).click();
        await expect(page.getByRole('dialog', { name: 'Share form' })).toBeVisible({ timeout: 10_000 });
        // The QR is a server round-trip; scanning before it lands would miss its alt text entirely.
        await expect(page.locator('img.share__qr')).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scan(page, 'share panel — live link', '[role="dialog"]');
    });

    test(`Builder — empty canvas (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Blank Intake Form');
        await expect(page.getByText('An empty form')).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scan(page, 'builder empty');
    });

    // The composite grid config editors (Increment G4b). The seeded "Grid Builder Demo" draft has a matrix
    // field FIRST, so the builder auto-selects it and its config tabs include "Grid" — mounting MatrixEditor
    // (rows/columns/cells lists). Walk the tabs (scanning the grid editor) with no palette interaction.
    test(`Builder — grid config editor (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Grid Builder Demo');
        await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);

        // The auto-selected matrix field exposes a "Grid" tab → MatrixEditor (rows + columns + cell choices).
        await page.getByRole('tab', { name: 'Grid' }).click();
        await expect(page.getByRole('heading', { name: 'Cell choices' })).toBeVisible({ timeout: 10_000 });
        await scan(page, 'matrix config editor');

        const fieldTabs = page.locator('[role="tab"]');
        for (let i = 0; i < (await fieldTabs.count()); i++) {
            await fieldTabs.nth(i).click();
            await scan(page, `grid demo tab ${i}`);
        }
    });

    // The geospatial config editor (Increment G5b2b). The seeded "Geo Builder Demo" draft has a geopoint
    // field FIRST, so the builder auto-selects it and its config tabs include "Map" — mounting GeoEditor
    // (capture options + default map view, numeric inputs, no map). Walk the tabs (scanning the geo editor)
    // with no palette interaction.
    test(`Builder — geo config editor (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Geo Builder Demo');
        await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);

        // The auto-selected geopoint field exposes a "Map" tab → GeoEditor (always shows "Default map view").
        await page.getByRole('tab', { name: 'Map' }).click();
        await expect(page.getByRole('heading', { name: 'Default map view' })).toBeVisible({ timeout: 10_000 });
        await scan(page, 'geo config editor');

        const fieldTabs = page.locator('[role="tab"]');
        for (let i = 0; i < (await fieldTabs.count()); i++) {
            await fieldTabs.nth(i).click();
            await scan(page, `geo demo tab ${i}`);
        }
    });

    // The LOGIC view (Increment H21d1) — the builder's second centre-pane view, and the largest read-only
    // surface in the app. `Logic Notices Demo` is seeded to carry every state the rail can draw at once: a
    // described condition, an opaque one, an invalid one, a forward-reference notice from the server, and a
    // section that can never be a step. Doc #27 §9 makes axe + keyboard traversal at 375px this row's
    // obligation, and the responsive-overflow lesson (H12b/H14/H15b) applies to it pre-emptively — hence the
    // `scan()` helper's own horizontal-overflow assertion at all three viewports.
    test(`Builder — logic view (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Logic Notices Demo');
        await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);

        await page.locator('.builder__centre-tabs').getByText('Logic').click();

        // Wait for the server-derived notices to land, so the scan sees the rail in its FULL state rather
        // than mid-check — the forward reference is the one thing on this page the client cannot derive.
        await expect(page.getByText(/comes later in the form/)).toBeVisible({ timeout: 15_000 });

        // Every reading state is on screen at once, which is what makes one scan worth six.
        await expect(page.getByText('Shown when Your age is more than 18.')).toBeVisible();
        await expect(page.getByText(/can’t be read/)).toBeVisible();
        await expect(page.getByText(/Nobody filling the form sees this section/)).toBeVisible();

        await scan(page, 'builder logic view');

        // Keyboard traversal: every node heading is reachable, and activating one drives the SAME config
        // panel the Structure view does — the property that keeps this a read-only view rather than a
        // second editor.
        const headings = page.locator('button.rail__head');
        expect(await headings.count()).toBeGreaterThan(1);
        await headings.first().focus();
        await expect(headings.first()).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('button.rail__head').first()).toHaveAttribute('aria-pressed', 'true');
        await scan(page, 'logic view after selection');

        // …and back, with the structure canvas intact.
        await page.locator('.builder__centre-tabs').getByText('Structure').click();
        await expect(page.locator('.canvas').first()).toBeVisible();
        await scan(page, 'back to structure');
    });

    // The structured CONDITION EDITOR (Increment H21d2) — the write half of the same row, and the config
    // panel's densest control group. Doc #27 §9 asks for axe plus keyboard traversal at 375px, which the
    // three-viewport project matrix gives; what this test adds is that BOTH modes are scanned, because they
    // are different DOM — the structured tree for a representable condition, the raw textarea for an opaque
    // one — and a scan of only the first would miss half the surface.
    test(`Builder — condition editor (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Logic Notices Demo');
        await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);

        await page.locator('.builder__centre-tabs').getByText('Logic').click();

        // The nested-group node: `(${age} > 18 or ${age} < 5) and selected(${colours}, 'red')` — the only
        // seeded shape that draws the recursive group control and a "Condition 1.2 …" ordinal.
        await page.locator('button.rail__head', { hasText: 'Grouped gate' }).click();
        await page.locator('[role="tab"]', { hasText: 'Advanced' }).click();

        await expect(page.getByRole('group', { name: 'Show this section only when…' })).toBeVisible();
        await expect(page.getByLabel('Condition 1.1 subject')).toBeVisible();
        await expect(page.getByLabel('Condition 2 option')).toBeVisible();
        await scan(page, 'condition editor — structured');

        // Keyboard: every control in the tree is reachable in order, and the last stop is the remove button
        // — an icon button whose only accessible name is its `aria-label`.
        await page.getByLabel('Condition 1.1 subject').focus();
        await expect(page.getByLabel('Condition 1.1 subject')).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByLabel('Condition 1.1 operator')).toBeFocused();

        // The OPAQUE arm: arithmetic, which the editor renders as raw text alone and never rewrites.
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        await page.locator('button.rail__head', { hasText: 'Arithmetic gate' }).click();
        await page.locator('[role="tab"]', { hasText: 'Advanced' }).click();

        await expect(page.getByLabel('Condition expression')).toHaveValue('${age} + 1 > 18');
        await expect(page.getByText(/never rewritten/)).toBeVisible();
        await expect(page.getByLabel('Condition 1 subject')).toHaveCount(0);
        await scan(page, 'condition editor — opaque fallback');
    });
}
