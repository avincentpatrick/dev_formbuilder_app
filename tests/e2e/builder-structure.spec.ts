import { test, expect, type Locator, type Page } from '@playwright/test';
import { assertClean } from './support/axe';
import { archive, createForm, reorderSent, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M150 (`D103`) — the user's Round 2 notes on Structure, in a real browser:
 *   `r2-c17` "can we also use the middle section (structure and preview) to allow the user to edit the label there already"
 *   `r2-c18` "the icon for the draggable on the left most part of the card is not noticeable. and if we can make the
 *            animation of the drag more smooth"
 *
 * A question's label is renamed on its row and survives a reload, and a real mouse drag — pressed on a grip, moved past
 * the threshold, carried over another row — reorders the form and survives a reload. Neither was exercised by any spec
 * before: the only reorder spec used the keyboard, and the only rename went through the settings pane.
 *
 * ── STATE: IT MAKES ITS OWN FORM, AND ARCHIVES IT ─────────────────────────────────────────────────────
 * `builder-content-axe.spec.ts`'s reason: every spec shares one seeded database across three viewport projects.
 */

function rows(page: Page): Locator {
    return page.locator('[data-drop-group="ungrouped"] [data-field-uid]');
}

async function structure(page: Page): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Structure').click();
}

async function rename(page: Page, formId: string, row: Locator, label: string): Promise<void> {
    await row.getByRole('button', { name: /^Edit label of / }).click();
    const input = row.getByRole('textbox', { name: 'Question label' });
    await expect(input).toBeFocused();
    await input.fill(label);
    const patched = page.waitForResponse(
        (response) => response.request().method() === 'PATCH' && response.url().includes(`/forms/${formId}/fields/`),
        { timeout: 20_000 },
    );
    await input.press('Enter');
    await patched;
    // The row is back, holding the new label, with the keyboard on it.
    await expect(row.locator('.canvas__field-main')).toContainText(label);
    await expect(row.locator('.canvas__field-main')).toBeFocused();
}

test('Builder — a label renamed on its row, and a question dragged by its grip, both saved', async ({ page }, info) => {
    test.setTimeout(180_000);
    const formId = await createForm(page, `Structure ${info.project.name} ${Date.now()}`);

    try {
        for (let i = 0; i < 3; i++) {
            await showBuilderPane(page, 'fields');
            await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
            await saved(page);
        }

        await structure(page);
        await expect(rows(page)).toHaveCount(3);
        for (const [i, label] of ['Alpha', 'Beta', 'Gamma'].entries()) {
            await rename(page, formId, rows(page).nth(i), label);
        }
        await saved(page);

        // The editor, open, is scanned once.
        await rows(page).nth(0).getByRole('button', { name: /^Edit label of / }).click();
        await assertClean(page, 'structure with a label edited in place');
        await page.keyboard.press('Escape');
        await expect(rows(page).nth(0).locator('.canvas__field-main')).toContainText('Alpha');

        // Alpha's grip, carried past the threshold and down over Gamma's lower half, then released.
        const grip = rows(page).nth(0).locator('.canvas__grip');
        const from = (await grip.boundingBox())!;
        const to = (await rows(page).nth(2).boundingBox())!;
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2 + 10, { steps: 2 });
        await expect(page.locator('.canvas__sr')).toContainText('Dragging Alpha');
        await page.mouse.move(from.x + from.width / 2, to.y + to.height * 0.85, { steps: 15 });
        const sent = reorderSent(page, formId);
        await page.mouse.up();
        await sent;
        await expect(page.locator('.canvas__sr')).toContainText('Dropped Alpha');
        await saved(page);

        // Both survive a reload.
        await page.reload({ waitUntil: 'networkidle' });
        await structure(page);
        await expect(rows(page).locator('.canvas__field-label')).toHaveText(['Beta', 'Gamma', 'Alpha']);
    } finally {
        await archive(page, formId);
    }
});
