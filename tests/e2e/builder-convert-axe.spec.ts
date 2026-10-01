import { test, expect, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';
import { openBuilder, showBuilderPane } from './support/navigate';

/**
 * Changing a question's type (Increment M123, `B5b`) — the dialog, scanned in the real app at every width and in both
 * themes.
 *
 * ⛔ THIS SPEC NEVER CONFIRMS A CONVERSION, AND IT PROVES IT. Every spec shares one database, and the seeded forms
 * are read by other specs — converting a field here would change what they find. So the dialog is opened, read and
 * CANCELLED, and the spec records every non-GET request to the builder's write routes and asserts there were none:
 * an accidental confirm fails loudly rather than corrupting a fixture for a later spec. Opening the dialog is a GET.
 *
 * The form is `Logic Notices Demo`, whose lowest-sequence question — the one the builder auto-selects — is `age`
 * (Whole number). Its sections are gated on `${age} > 18` and `${age} + 1 > 18`, so the plan to "Hidden field" carries
 * a real census: the dialog's "Elsewhere in the form" list is scanned with content in it, not empty.
 */
const themes = ['light', 'dark'] as const;

/** The dialog is modal: the builder behind it must be inert, and the dialog itself must not be — or axe scans nothing. */
async function assertBackgroundInert(page: Page): Promise<void> {
    expect(
        await page.locator('.builder__panes').evaluate((el) => el.closest('[inert]') !== null),
        'the builder behind the dialog should be inert',
    ).toBe(true);
    expect(
        await page.getByRole('dialog', { name: 'Change question type' }).evaluate((el) => el.closest('[inert]') === null),
        'the dialog itself must NOT be inert — otherwise the scan below measures nothing',
    ).toBe(true);
}

for (const theme of themes) {
    test(`Builder — change question type dialog (${theme})`, async ({ page }) => {
        const writes: string[] = [];
        page.on('request', (request) => {
            if (request.method() !== 'GET' && /\/forms\/[0-9a-f-]{36}\/(fields|sections|reorder)/.test(request.url())) {
                writes.push(`${request.method()} ${request.url()}`);
            }
        });

        await openBuilder(page, 'Logic Notices Demo');
        await showBuilderPane(page, 'settings');
        const control = page.locator('[data-field-type-control]');
        await expect(control).toContainText('Whole number', { timeout: 10_000 });
        await forceTheme(page, theme);

        const opener = control.getByRole('button', { name: 'Change type' });
        await opener.focus();
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name: 'Change question type' });
        await expect(dialog).toBeVisible();
        const target = dialog.getByLabel('Change to');
        // Focus lands on the choice once the plans arrive — the dialog's whole point is that input.
        await expect(target).toBeFocused({ timeout: 10_000 });
        await expect(dialog.locator('optgroup')).toHaveCount(2);

        await assertBackgroundInert(page);
        await assertClean(page, `change type — choosing (${theme})`);

        await target.selectOption('hidden');
        await expect(dialog).toContainText('A hidden question holds nothing until you choose where its value comes from.');
        await expect(dialog).toContainText('the section “Adults only”');
        await assertClean(page, `change type — consequences (${theme})`);

        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
        await expect(control).toContainText('Whole number');

        expect(writes, 'the dialog must be opened and cancelled without writing anything').toEqual([]);
    });
}
