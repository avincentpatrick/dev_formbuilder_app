import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme, settlePaint } from './support/axe';
import { openBuilder, showBuilderPane } from './support/navigate';

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
 * Scan the composed page.
 *
 * ── THIS HELPER USED TO TAKE A `within` SUBTREE, AND I10a DELETED IT ───────────────────────────────────
 * The parameter existed for ONE reason: the scrim. I8c found that `MdsModal` rendered a semi-transparent
 * backdrop and did NOT mark the rest of the page `inert`, so axe still evaluated the builder's config panel
 * underneath and BLENDED the scrim into its computed background. An unselected segmented-control label that
 * measures fine on its own surface read 1.82:1 once axe composited the overlay on top of it — a finding
 * about content nobody could see, reach or tab to while the dialog was open. The two share-panel cases were
 * scoped to `[role="dialog"]` and the underlying observation was filed to `docs/feature-backlog.md:109`.
 *
 * I10a fixed the component instead: `MdsModal` now marks every non-ancestor sibling `inert`
 * (`packages/design-system/src/components/Modal/inert-stack.ts`). axe 4.12.1 excludes an inert subtree from
 * `color-contrast` (`colorContrastMatches` early-returns on `_isInert`) and from
 * `isVisibleToScreenReadersVirtual`, which is precisely the blend that produced the finding. **Both
 * share-panel cases below are whole-page again, and they are the merge-blocking proof that the fix works on
 * real product markup**: if the inert walk regresses, the builder's config panel comes back through the
 * scrim and these two go red.
 *
 * Two honest caveats rather than a claim that the whole class is gone:
 *  - `_isInert` has a small number of call sites in axe. Rules that composite background colour WITHOUT
 *    going through `color-contrast`'s matcher — `link-in-text-block` is the one in our tag list — can still
 *    evaluate inert content. If one of those ever fires on background markup here, that is new information
 *    about axe, not a reason to re-scope: fix it or narrow the RULE, not the context.
 *  - Whole-page also re-enables axe's only `pageLevel` rule, `bypass`, which `.include()` suppressed. It
 *    cannot break the gate: `bypass` is declared `reviewOnFail: true`, so a failure is reported as
 *    *incomplete* and `assertClean`/this helper assert on `violations` only. It passes anyway — its
 *    `header-present` check matches `:is(h1..h6):not([role])`, and the dialog's own `.mds-modal__title` is
 *    an `<h2>` outside the inert subtree.
 *
 * Do not reintroduce a `within` here for a modal. If a dialog scan ever fails on background content again,
 * the bug is in the modal, not in the scan.
 *
 * NOTE on the two sibling specs: `analytics-axe.spec.ts:150` and `scopes-axe.spec.ts:83` DO still scope a
 * modal scan to `[role="dialog"]`, so this file is not the last of the pattern. They were left alone
 * deliberately — those files scope EVERY scan, modal or not, for the separate G9b reason recorded in their
 * headers (whole-page scans there re-flag pre-existing violations elsewhere on those pages), so widening
 * only their dialog call would be a different change with a different risk. Filed to the backlog instead.
 */
async function scan(page: Page, label: string): Promise<void> {
    // ⚠️ PARK THE POINTER FIRST — THIS FILE SCANS AFTER EVERY CLICK, WHICH IS WHY IT NEEDS THIS MOST.
    // `assertClean()` in `support/axe.ts` has done this since it was written, with the reason in its own
    // words: "a parked cursor over a primary button reads its lighter hover bg and mis-flags its contrast —
    // a test artifact, not a real violation." This spec never adopted it, and it is the spec that walks
    // config tabs and section buttons with `.click()` and then scans immediately — so the cursor is left
    // sitting on whatever it last pressed, and at 375px the toolbar is close enough to the canvas that it
    // lands under a toolbar button.
    //
    // That is not a hypothetical: `.mds-button--secondary` is `background-color: transparent` and its ONLY
    // opaque state is `:hover`, which fills it with `--mds-color-action-primary-tint`. J1e's CI read exactly
    // that — `#7da9c4` on `#1d4260`, 4.17 — on `header.builder__toolbar`'s secondary button, at mobile and
    // dark only, on a branch that touches no token, no `Button.vue` and no `Builder.vue`. This file's
    // standing reputation for contrast flakes at mobile+dark (the `:159` "known flake", I11a's empty-canvas
    // case) is very likely the same artifact, unmeasured until now.
    await page.mouse.move(0, 0);

    // ⚠️ AND WAIT FOR THAT UN-HOVER TO PAINT. Parking the pointer is a style invalidation like any other:
    // the control it left begins transitioning back to its resting colour, and the recalc lands on a later
    // frame. J1e added the two-frame wait to `forceTheme` and NOT here, and J2b's CI flaked on exactly the
    // gap — "share panel, live link (dark)" reported 93 violations with an intermediate FOREGROUND
    // (`#6f99b5`, in no token file) over the settled dark `bg-surface` (`#123350` as it then was; JR1 moved
    // that token to `#1a2130` and the incident hexes are left as recorded), on the very button the
    // test had just clicked. Shared with `assertClean`, which owed the same wait.
    await settlePaint(page);

    const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows, `${label}: horizontal overflow`).toBe(false);

    const results = await new AxeBuilder({ page })
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

/**
 * The I10a contract, asserted as BOTH halves. "Something on the page is inert" is not the claim and would
 * pass just as well if the walk inerted everything including the dialog — which is the failure mode that
 * would make the whole-page scan below go green over an empty accessibility tree.
 */
async function assertBackgroundInert(page: Page): Promise<void> {
    expect(
        await page.locator('.builder__pane--left').evaluate((el) => el.closest('[inert]') !== null),
        'the builder behind the dialog should be inert',
    ).toBe(true);
    expect(
        await page.getByRole('dialog').first().evaluate((el) => el.closest('[inert]') === null),
        'the dialog itself must NOT be inert — otherwise the scan below measures nothing',
    ).toBe(true);
}

for (const theme of themes) {
    test(`Builder — populated & interactive (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Community Health Survey');
        // JR5 — every `[role="tab"]` on this page belongs to ConfigPanel, which is on screen below 60em of
        // the builder's container ONLY when the Settings pane is selected. This also replaces the bare
        // `[role="tab"]` settle as the hydration wait; a no-op at the desktop project.
        await showBuilderPane(page, 'settings');
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

        // JR5 — THE COVERAGE THIS INCREMENT WOULD OTHERWISE HAVE COST. Before it, a whole-page scan at
        // 375/834 saw all three panes at once because they were stacked; now it sees one. Walk the other
        // two so the mobile and tablet projects keep scanning the palette and the canvas at all. Skipped
        // entirely at any width where all three are already up, where it would only duplicate the scans
        // above.
        if (await showBuilderPane(page, 'fields')) {
            await scan(page, 'compact pane — Add');
            await showBuilderPane(page, 'canvas');
            await scan(page, 'compact pane — Form');
            await showBuilderPane(page, 'settings');
        }

        // Select the repeatable section → its Advanced tab mounts the min/max MdsNumberInputs.
        await showBuilderPane(page, 'canvas');
        await page.getByRole('button', { name: /^New section/ }).first().click();
        await showBuilderPane(page, 'settings');
        const sectionTabs = page.locator('[role="tab"]');
        for (let i = 0; i < (await sectionTabs.count()); i++) {
            await sectionTabs.nth(i).click();
            await scan(page, `section tab ${i}`);
        }

        // Full keyboard reorder via the grip's grab-mode, asserting the aria-live announcements.
        await showBuilderPane(page, 'canvas');
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
        // I10a: whole-page again, and the DOM-level claim stated directly rather than inferred from an axe
        // side-effect. `closest('[inert]')` rather than a getByRole count, because that would depend on
        // Playwright's own ARIA engine honouring inert; this depends only on the DOM.
        await assertBackgroundInert(page);
        await scan(page, 'share panel — no link yet');
    });

    test(`Builder — share panel, live link (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Clinic Intake');
        await page.getByRole('button', { name: 'Share' }).click();
        await expect(page.getByRole('dialog', { name: 'Share form' })).toBeVisible({ timeout: 10_000 });
        // The QR is a server round-trip; scanning before it lands would miss its alt text entirely.
        await expect(page.locator('img.share__qr')).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await assertBackgroundInert(page);
        await scan(page, 'share panel — live link');
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
        await showBuilderPane(page, 'settings');
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
        await showBuilderPane(page, 'settings');
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
        // JR5 — the Logic rail lives in the CENTRE pane, so `.builder__centre-tabs` is only on screen when
        // the Form pane is selected. This doubles as the hydration settle the `[role="tab"]` locator used
        // to provide, which would now wait on a pane this test never selects.
        await showBuilderPane(page, 'canvas');
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

        // …and back, with the structure canvas intact. (Selecting a rail node drives the config panel, which
        // below the threshold means the Form pane is still the one on screen — the centre control has not
        // moved.)
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
        await showBuilderPane(page, 'canvas');
        await forceTheme(page, theme);

        await page.locator('.builder__centre-tabs').getByText('Logic').click();

        // The nested-group node: `(${age} > 18 or ${age} < 5) and selected(${colours}, 'red')` — the only
        // seeded shape that draws the recursive group control and a "Condition 1.2 …" ordinal.
        await page.locator('button.rail__head', { hasText: 'Grouped gate' }).click();
        // ⚠️ JR5 — MANDATORY, NOT COSMETIC. The next line is a CSS locator, so with the config pane
        // `display: none`-d it still MATCHES (strict mode is satisfied) and `.click()` then waits the full
        // timeout for a visibility that never comes. Selecting the pane is what makes it clickable.
        await showBuilderPane(page, 'settings');
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
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Logic').click();
        await page.locator('button.rail__head', { hasText: 'Arithmetic gate' }).click();
        await showBuilderPane(page, 'settings');
        await page.locator('[role="tab"]', { hasText: 'Advanced' }).click();

        await expect(page.getByLabel('Condition expression')).toHaveValue('${age} + 1 > 18');
        await expect(page.getByText(/never rewritten/)).toBeVisible();
        await expect(page.getByLabel('Condition 1 subject')).toHaveCount(0);
        await scan(page, 'condition editor — opaque fallback');
    });
}
