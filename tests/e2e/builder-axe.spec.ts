import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Interaction-driven accessibility gate for the form builder (Increment D4b) — the single highest-risk
// surface. Unlike the goto-only responsive-axe spec, this DRIVES the builder's real interactive states:
// the config panel + tabs (mounting MdsSelect/MdsTextarea/MdsNumberInput/MdsCheckbox/MdsSegmentedControl),
// the section editor, and a full keyboard reorder (grab → arrow → drop) with aria-live assertions — at all
// three viewports (the config's projects) in light AND dark. Also scans the empty-canvas state. The
// builder auto-selects a field on load so the config panel is mounted for the scan.

const themes = ['light', 'dark'] as const;

async function forceTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => {
        if (t === 'dark') document.documentElement.setAttribute('data-theme-mode', 'dark');
        else document.documentElement.removeAttribute('data-theme-mode');
    }, theme);
}

async function scan(page: Page, label: string): Promise<void> {
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

    test(`Builder — empty canvas (${theme})`, async ({ page }) => {
        await openBuilder(page, 'Blank Intake Form');
        await expect(page.getByText('An empty form')).toBeVisible({ timeout: 10_000 });
        await forceTheme(page, theme);
        await scan(page, 'builder empty');
    });
}
