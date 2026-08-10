import { expect, test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

// The ⌘K command palette (Increment J1d, DSR §3.4.1).
//
// ⚠️ THIS FILE IS THE PALETTE'S ONLY AUTOMATED ACCESSIBILITY GATE, and that is recorded in the DSR and in
// exceptions-log #9 rather than left to be assumed. Storybook globs
// `packages/design-system/src/**/*.stories.@(ts|tsx)` only, so an app-tree component gets no story and no
// `checkA11y` scan — the `design-system-a11y` job passing says nothing whatsoever about this component.
//
// The ARIA details axe structurally cannot see — an `aria-activedescendant` pointing at an id that is not
// in the DOM, an `aria-controls` left dangling while the listbox is unrendered — are asserted in
// `CommandPalette.test.ts` instead. What lives here is what only a real browser can answer: that the chord
// actually reaches the document, that the dialog is operable by keyboard alone, and that an OPEN palette
// scans clean at every viewport.
//
// ⚠️ WRITTEN BUT NOT RUN LOCALLY (process rule 9). Playwright's `global-setup` cannot complete against this
// repo's local stack — sign-in authenticates and session rows carry the right `user_id`, but the browser
// never lands on `/dashboard`. It reproduces on specs that were green before J1b, and nothing in J1b–J1d
// touches authentication, so it is environmental. CI runs with `SESSION_DRIVER=file`, `CACHE_STORE=file`
// and `/etc/hosts` entries; treat the first CI run of this file as its real first run.

/** ⌘K on macOS, Ctrl+K elsewhere. Playwright's `Meta` maps to the platform's primary modifier. */
const CHORD = process.platform === 'darwin' ? 'Meta+k' : 'Control+k';

test.describe('command palette', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await page.locator('.topnav').waitFor({ state: 'visible', timeout: 10_000 });
    });

    test('opens on the chord from anywhere on the page', async ({ page }) => {
        await page.keyboard.press(CHORD);

        const dialog = page.getByRole('dialog', { name: 'Search' });
        await expect(dialog).toBeVisible();

        // Initial focus is the input, not the Close button — the whole reason J1a added `initialFocus`.
        // `focusable()` walks the panel in DOM order and the header precedes the body, so without the prop
        // every modal opens focused on its own Close.
        await expect(page.getByRole('combobox')).toBeFocused();
    });

    test('opens even from inside a text field, deliberately', async ({ page }) => {
        // A modifier chord does not collide with typing, and the builder's editors are exactly where a
        // user wants it — DSR §3.4.1 records that there is no tag guard on purpose.
        const navSearch = page.getByRole('searchbox', { name: 'Search this workspace' });
        await navSearch.click();
        await navSearch.type('cl');

        await page.keyboard.press(CHORD);

        await expect(page.getByRole('dialog', { name: 'Search' })).toBeVisible();
    });

    test('toggles shut on a second chord', async ({ page }) => {
        await page.keyboard.press(CHORD);
        await expect(page.getByRole('dialog', { name: 'Search' })).toBeVisible();

        // The guard-order property, end to end: `openModalCount()` now counts our own palette, so a handler
        // that checked the count before its own state could never close it.
        await page.keyboard.press(CHORD);
        await expect(page.getByRole('dialog', { name: 'Search' })).toBeHidden();
    });

    test('is operable by keyboard alone, all the way to a destination', async ({ page }) => {
        await page.keyboard.press(CHORD);

        const input = page.getByRole('combobox');
        await input.fill('health');

        // Wait for the debounced suggest round trip to land a real option.
        await page.getByRole('option').first().waitFor({ state: 'visible', timeout: 15_000 });

        // DOM focus must never leave the input while the active option moves.
        await page.keyboard.press('ArrowDown');
        await expect(input).toBeFocused();

        const active = await input.getAttribute('aria-activedescendant');
        expect(active, 'aria-activedescendant is not tracking the arrow keys').toBeTruthy();
        await expect(page.locator(`#${active}`)).toHaveAttribute('role', 'option');

        await page.keyboard.press('Enter');
        await page.waitForURL(/\/(forms|submissions|members|search)/, { timeout: 15_000 });
    });

    test('closes on Escape and returns focus somewhere real', async ({ page }) => {
        await page.keyboard.press(CHORD);
        await expect(page.getByRole('dialog', { name: 'Search' })).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: 'Search' })).toBeHidden();

        // ⚠️ NOT `<body>`. The chord path focuses the nav field before opening precisely so MdsModal
        // captures a focusable opener — `document.body.focus()` is a silent no-op, and a keyboard user
        // would otherwise be stranded, which is the outcome DSR §4.5 forbids.
        const active = await page.evaluate(() => document.activeElement?.tagName ?? '');
        expect(active).not.toBe('BODY');
    });

    test('finds a page by a word the product does not use for it', async ({ page }) => {
        // The destinations arm (J1c) reaching the palette — the keyword catalog is what makes the shortcut
        // useful to someone who does not know the product's noun for a screen.
        await page.keyboard.press(CHORD);
        await page.getByRole('combobox').fill('people');

        await expect(page.getByRole('option').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('listbox')).toContainText('Members');
    });
});

for (const theme of ['light', 'dark'] as const) {
    test(`Command palette, open (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await page.locator('.topnav').waitFor({ state: 'visible', timeout: 10_000 });

        await page.keyboard.press(CHORD);
        await page.getByRole('combobox').fill('health');
        await page.getByRole('option').first().waitFor({ state: 'visible', timeout: 15_000 });

        await forceTheme(page, theme);
        // The scan that the design-system a11y job cannot perform for this component.
        await assertClean(page, `Command palette (${theme})`);
    });
}
