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
        //
        // ⚠️ THE FIELD IS INJECTED RATHER THAN BORROWED FROM THE NAV, and CI is why. The first version
        // clicked the nav searchbox, which does not exist at 375px — below the 480px breakpoint it is
        // `display: none` and the compact icon link replaces it — so the click timed out at mobile and
        // passed everywhere else. A temporary textarea tests the actual property (a chord fired from
        // inside a text field) at every viewport, without depending on which affordance the layout
        // happens to be showing.
        await page.evaluate(() => {
            const field = document.createElement('textarea');
            field.id = 'chord-probe';
            document.body.appendChild(field);
            field.focus();
        });

        await page.locator('#chord-probe').type('cl');
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

        // ⚠️ NOT `<body>`. The chord path focuses a VISIBLE nav affordance before opening precisely so
        // MdsModal captures a focusable opener — `document.body.focus()` is a silent no-op, and a keyboard
        // user would otherwise be stranded, which is the outcome DSR §4.5 forbids.
        //
        // This is the case that caught the real bug: passing only `#topnav-search` stranded focus at
        // 375px, where that field is `display: none`. The assertion NAMES the element rather than merely
        // ruling out BODY, because "not BODY" would also pass if focus landed somewhere arbitrary — and a
        // weak assertion here is what would let the same class of bug back in.
        const active = await page.evaluate(() => ({
            tag: document.activeElement?.tagName ?? '',
            id: document.activeElement?.id ?? '',
            cls: document.activeElement?.className ?? '',
        }));

        expect(active.tag, `focus was stranded on ${active.tag}`).not.toBe('BODY');

        const isNavSearch = active.id === 'topnav-search' || String(active.cls).includes('topnav__search-compact');
        expect(isNavSearch, `focus returned to ${active.tag}#${active.id}.${active.cls} instead of a nav search control`).toBe(true);
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
