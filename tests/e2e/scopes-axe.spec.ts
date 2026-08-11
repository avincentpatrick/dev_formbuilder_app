import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { forceTheme, settlePaint } from './support/axe';

/*
 * The scoping hierarchy page (Increment G10b2) — the app's first tree widget, so this spec carries most of
 * the structural-ARIA risk in the increment.
 *
 * SCOPED, not whole-page, per the G9b lesson: assertClean re-flags PRE-EXISTING violations elsewhere on the
 * page (that is what cost G9b three follow-up pushes), so the scan is narrowed to the new pane and drops the
 * horizontal-overflow check. The whole-page scan for /scopes lives in responsive-axe, which owns overflow.
 *
 * Note the include is `.scopes__pane`, NOT `.scopes__tree`: the assertive live region is deliberately a
 * SIBLING of the tree element rather than a child, because anything carrying a global aria-* attribute
 * inside role="tree" is treated by axe as an owned child and fails aria-required-children. Scanning only
 * .scopes__tree would leave the announcer uncovered.
 */

const themes = ['light', 'dark'] as const;

async function scanPane(page: Page, selector: string, label: string): Promise<void> {
    await page.mouse.move(0, 0);

    // Wait for the un-hover to PAINT — see `support/axe.ts`'s `assertClean`: parking the pointer starts a
    // transition on the control it left, and a scan issued on the same frame can read an intermediate
    // foreground against a settled background. J2b's CI flaked on exactly that in `builder-axe`.
    await settlePaint(page);
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

async function openScopes(page: Page): Promise<void> {
    await page.goto('/scopes', { waitUntil: 'networkidle' });
    await expect(page.getByRole('tree', { name: 'Scope hierarchy' })).toBeVisible();
}

/** Expand by keyboard. A row CLICK selects — the twisty is an aria-hidden span with @click.stop — so a
 *  `treeitem.click()` would never expand and every descendant assertion after it would time out. */
async function expandRow(page: Page, name: RegExp): Promise<void> {
    const row = page.getByRole('treeitem', { name });
    await row.focus();
    await page.keyboard.press('ArrowRight');
    await expect(row).toHaveAttribute('aria-expanded', 'true');
}

for (const theme of themes) {
    test(`Scopes — tree and detail panel (${theme})`, async ({ page }) => {
        await openScopes(page);
        await forceTheme(page, theme);

        await scanPane(page, '.scopes__pane', `scopes tree collapsed (${theme})`);

        await expandRow(page, /^Luzon/);
        await expandRow(page, /National Capital Region/);
        await expect(page.getByRole('treeitem', { name: /City of Manila/ })).toBeVisible();

        await scanPane(page, '.scopes__pane', `scopes tree expanded (${theme})`);

        // Selecting loads the grant list through the sidecar, so the detail panel has real content to scan.
        await page.getByRole('treeitem', { name: /National Capital Region/ }).click();
        await expect(page.getByRole('heading', { name: 'Who can reach this' })).toBeVisible();
        await scanPane(page, '.scopes__detail', `scopes detail panel (${theme})`);
    });

    test(`Scopes — grant modal with blast-radius preview (${theme})`, async ({ page }) => {
        await openScopes(page);
        await forceTheme(page, theme);

        await expandRow(page, /^Luzon/);
        await page.getByRole('treeitem', { name: /National Capital Region/ }).click();
        await page.getByRole('button', { name: 'Grant access' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        // The confirm relabels to the verb + count once the preview resolves; until then it is disabled.
        await expect(dialog.getByRole('button', { name: /Grant access to \d+ forms?/ })).toBeVisible({ timeout: 10_000 });

        await scanPane(page, '[role="dialog"]', `grant modal (${theme})`);
    });
}

test('the tree exposes correct ARIA structure', async ({ page }) => {
    await openScopes(page);

    const luzon = page.getByRole('treeitem', { name: /^Luzon/ });
    await expect(luzon).toHaveAttribute('aria-level', '1');
    await expect(luzon).toHaveAttribute('aria-expanded', 'false');

    await expandRow(page, /^Luzon/);
    const ncr = page.getByRole('treeitem', { name: /National Capital Region/ });
    await expect(ncr).toHaveAttribute('aria-level', '2');
    // Per-PARENT sibling set: NCR is 1 of Luzon's children, NOT its index in the flattened visible list
    // (which would be 2). This is the flat-tree defect axe cannot catch.
    await expect(ncr).toHaveAttribute('aria-posinset', '1');

    await expandRow(page, /National Capital Region/);
    const manila = page.getByRole('treeitem', { name: /City of Manila/ });
    await expect(manila).toHaveAttribute('aria-level', '3');
    await expect(manila).toHaveAttribute('aria-setsize', '2');

    // A leaf OMITS aria-expanded rather than setting it false — otherwise screen readers announce
    // "collapsed" and users hunt for children that do not exist.
    await expandRow(page, /City of Manila/);
    await expandRow(page, /^Malate/);
    await expect(page.getByRole('treeitem', { name: /Zone 82/ })).not.toHaveAttribute('aria-expanded');
});

test('roving tabindex keeps the tree to a single tab stop', async ({ page }) => {
    await openScopes(page);
    await expandRow(page, /^Luzon/);

    // Exactly one row is tabbable at a time; the rest are -1. Without this, a 200-node hierarchy would put
    // hundreds of stops in the tab order.
    await expect(page.locator('.scopes__tree > li[tabindex="0"]')).toHaveCount(1);
    await expect(page.locator('.scopes__tree > li[tabindex="-1"]').first()).toBeVisible();
});

test('keyboard move announces grab, target and outcome', async ({ page }) => {
    await openScopes(page);
    await expandRow(page, /^Luzon/);

    const status = page.locator('.scopes__sr');

    // "Movable District" is seeded for this spec alone, off the Luzon branch the ARIA-structure test
    // asserts on. This spec runs once per viewport project against ONE seeded database (workers: 1), so a
    // move test that re-parented a shared node would pass on the first project and break the others.
    //
    // The reveal is conditional for the same reason: on a fresh database the node sits two levels down, but
    // once this test has re-rooted it it is a top-level row and its former parent has no children left to
    // expand. Both states have to reach the same node.
    const movable = page.getByRole('treeitem', { name: /Movable District/ });
    if (!(await movable.isVisible())) {
        await expandRow(page, /^Visayas/);
        await expandRow(page, /Central Visayas/);
    }
    await movable.click();
    await page.getByRole('button', { name: 'Move…' }).click();
    await expect(status).toContainText('Grabbed');

    // Drop straight onto the synthetic "top level" target — the cursor's initial position, and the only way
    // move(node, null) is reachable. The confirm step is deliberate: a move can be REFUSED server-side
    // (cycle, depth cap), so nothing is previewed or committed until it is confirmed. Re-rooting is also
    // idempotent, so repeat runs land in the same asserted state.
    await page.keyboard.press('Enter');
    await page.getByRole('button', { name: 'Move scope' }).click();

    await expect(status).toContainText('Moved', { timeout: 10_000 });
    // Assert the PERSISTED position, not just the announcement: ScopeNodeService::move() early-returns
    // unchanged when the resolved parent already matches, so a live-region-only assertion could pass
    // vacuously. On a freshly seeded database this node starts at depth 2 (aria-level 3), so the first run
    // genuinely proves the re-parent; later runs re-assert the same end state.
    await expect(page.getByRole('treeitem', { name: /Movable District/ })).toHaveAttribute('aria-level', '1');
});

test('pointer targets meet the 24px minimum', async ({ page }) => {
    await openScopes(page);

    // Asserted directly rather than left to axe: its target-size rule returns INCOMPLETE (not a violation)
    // for anything outside the tab order, and under roving tabindex every grip but one is tabindex="-1".
    // So the axe gate would silently not catch an undersized grip.
    for (const selector of ['.scopes__grip', '.scopes__twisty']) {
        const box = await page.locator(selector).first().boundingBox();
        expect(box, `${selector} should be rendered`).not.toBeNull();
        expect(box!.width, `${selector} width`).toBeGreaterThanOrEqual(24);
        expect(box!.height, `${selector} height`).toBeGreaterThanOrEqual(24);
    }
});
