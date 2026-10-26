import { test, expect, type Page } from '@playwright/test';
import { assertClean } from './support/axe';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M188 (`R-3894369d`, relayed `P5b`, `D167`) — a question's history and Put back, in a real browser. The testers
 * (2026-10-11, comment 5): "a ledger/list/log of the nitty-gritty changes per field … an option to revert changes … state
 * what the previous state was before applying reversal".
 *
 * ⛔ THE CASE ONLY THE REAL STACK CAN SETTLE: the history is written by the server inside the save's own transaction, read
 * back over the network, and Put back goes out as an ordinary save — one PATCH naming the entry, one undo entry. The store
 * test drives a stubbed `fetch`; here the server decides what was recorded.
 *
 * Desktop only: the tab sits in the settings panel at every width (the axe walk in `builder-axe.spec.ts` covers every
 * width), and the panes switcher is not what is under test. It makes its own form and archives it (M139's reason).
 */

/** A history read is a network round trip, as a save is: the same allowance `saved()` gives one. */
const READ = { timeout: 20_000 };

async function settled(page: Page): Promise<void> {
    await page.waitForTimeout(800);
    await saved(page);
}

async function configTab(page: Page, name: string): Promise<void> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name, exact: true }).click();
}

test('Builder — History lists a saved change, Put back shows it first and restores it, and Undo reverses the Put back', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the History tab is the same at every width');
    test.setTimeout(180_000);
    const formId = await createForm(page, `Question history ${Date.now()}`);

    try {
        await showBuilderPane(page, 'fields');
        await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
        await settled(page);
        const label = page.getByLabel('Label', { exact: true });
        await label.fill('Household size');
        await settled(page);

        // The label the server gave the new question is read from the history itself: the box shows the palette's name
        // for an instant before the server's answer replaces it, so reading the box would race that answer.
        await configTab(page, 'History');
        const history = page.locator('[data-field-history]');
        const change = history.locator('[data-history-change]').first();
        await expect(change).toHaveText(/^Label: .+ → changed to Household size$/, READ);
        const original = ((await change.textContent()) ?? '').replace(/^Label: /, '').replace(/ → changed to Household size$/, '').trim();
        expect(original).not.toBe('');

        await assertClean(page, 'the History tab');

        await history.locator('[data-put-back]').first().click();
        const dialog = page.getByRole('dialog', { name: 'Put back this change?' });
        await expect(dialog.locator('[data-put-back-row]')).toHaveText([`LabelHousehold size${original}`]);
        await assertClean(page, 'the Put back dialog');
        await dialog.getByRole('button', { name: 'Put back', exact: true }).click();
        await expect(history.locator('[data-history-outcome="done"]')).toBeVisible(READ);
        await saved(page);

        await configTab(page, 'Basics');
        await expect(label).toHaveValue(original);

        // The Put back is listed as its own entry, saying which change it put back.
        await configTab(page, 'History');
        await expect(history.locator('[data-history-kind="put_back"]').first()).toContainText('Put back the change of', READ);

        await page.getByRole('button', { name: 'Undo' }).click();
        await settled(page);
        await configTab(page, 'Basics');
        await expect(label).toHaveValue('Household size');
    } finally {
        await archive(page, formId);
    }
});
