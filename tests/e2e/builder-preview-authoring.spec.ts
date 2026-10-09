import { test, expect, type Locator, type Page } from '@playwright/test';
import { assertClean } from './support/axe';
import { archive, createForm, reorderSent, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M139 (`R-598b9100`, `R-c0857303`) — the user's staging smoke test, comment 18: "section must be added in the structure
 * before it shows on the preview … the preview page must have a capacity to have its own way to add section, add specific
 * indicator in it … the indicators must be draggable to sections".
 *
 * In a real browser, on a fresh form: a section added FROM THE PREVIEW shows there at once as an author-only placeholder
 * (the engine never makes an empty section a step), its "Add a question to …" opens the palette with focus on it, the
 * question added lands in that section, and keyboard grab mode moves it back out — the move it could not make before.
 *
 * ── STATE: IT MAKES ITS OWN FORM, AND ARCHIVES IT ─────────────────────────────────────────────────────
 * `builder-content-axe.spec.ts`'s reason: every spec shares one seeded database across three viewport projects.
 */

test('Builder — a section and its question added from the preview, and moved out by keyboard', async ({ page }, info) => {
    test.setTimeout(150_000);
    const formId = await createForm(page, `Preview authoring ${info.project.name} ${Date.now()}`);

    try {
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Preview').click();
        await expect(page.locator('[data-builder-preview]')).toBeVisible({ timeout: 15_000 });

        // An empty form: the preview offers both adds.
        await page.locator('[data-preview-add-section]').click();
        const placeholder = page.locator('[data-preview-empty-section]');
        await expect(placeholder).toBeVisible({ timeout: 15_000 });
        await saved(page);
        const sectionTitle = (await placeholder.locator('h3').innerText()).trim();
        await assertClean(page, 'builder preview with an empty section');

        // Its add selects the section and brings the palette, with focus on it.
        await placeholder.getByRole('button', { name: `Add a question to ${sectionTitle}` }).click();
        await expect.poll(() => page.evaluate(() => document.activeElement?.closest('.palette') !== null)).toBe(true);
        await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
        await saved(page);

        // The question is in that section: Structure shows it inside the section's drop zone, and the placeholder is gone.
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Structure').click();
        const sectionId = await page.locator('[data-drop-group]').nth(1).getAttribute('data-drop-group');
        await expect(page.locator(`[data-drop-group="${sectionId}"] [data-field-uid]`)).toHaveCount(1);
        await expect(page.locator('[data-section-empty]')).toHaveCount(0);

        // Keyboard grab: the section's only question moves up to the top of the form, which it could not before.
        await page.locator(`[data-drop-group="${sectionId}"] .canvas__grip`).focus();
        await page.keyboard.press('Enter');
        await page.keyboard.press('ArrowUp');
        await expect(page.locator('[data-drop-group="ungrouped"] [data-field-uid]')).toHaveCount(1);
        // M150: the step out of the section rebuilt the row, and focus must follow it to the new grip — before, the
        // drop below landed on the page, the move was never saved, and every later drag was refused.
        await expect(page.locator('[data-drop-group="ungrouped"] .canvas__grip')).toBeFocused();
        const sent = reorderSent(page, formId);
        await page.keyboard.press('Enter');
        await expect(page.locator('.canvas__sr')).toContainText('Dropped');
        await sent;
        await expect(page.locator('[data-section-empty]')).toBeVisible();
        await saved(page);

        // And it was saved: after a reload the question is still at the top of the form.
        await page.reload({ waitUntil: 'networkidle' });
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Structure').click();
        await expect(page.locator('[data-drop-group="ungrouped"] [data-field-uid]')).toHaveCount(1);
    } finally {
        await archive(page, formId);
    }
});

/*
 * M145 (`R-551873af`) — a half-built rule row no longer turns the whole preview relevant.
 *
 * Basics → Requiredness "Conditional" → "Add condition" seeds a `required_if` with no operator and no compared
 * question, and the server saves it as it is (the save door is `nullable` on purpose). Until M145 the engine's
 * lowering threw on that row at mount, `safeEvaluate()` degraded the preview to everything-relevant, and the
 * preview said nothing — a question hidden behind a condition simply appeared. Now the projection omits the row
 * and the preview shows a chip under the question that owns it.
 *
 * The proof is the hidden question STAYING hidden after the seed: red on the unfixed bundle, where it shows.
 */

/** The autosave debounces 600 ms, so "All changes saved" read earlier than that is the PREVIOUS save's. */
async function settled(page: Page): Promise<void> {
    await page.waitForTimeout(800);
    await saved(page);
}

async function configTab(page: Page, name: string): Promise<void> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name, exact: true }).click();
}

/** Add a Text question from the palette, then give it a label and a key so a condition can name it. */
async function addText(page: Page, label: string, key: string): Promise<void> {
    await showBuilderPane(page, 'fields');
    await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
    await settled(page);

    // Adding selects the new question, so the config panel is already on it.
    await configTab(page, 'Basics');
    await page.getByLabel('Label', { exact: true }).fill(label);
    await configTab(page, 'Advanced');
    await page.getByLabel('Field key', { exact: true }).fill(key);
    await settled(page);
}

async function selectField(page: Page, label: string): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Structure').click();
    await page.locator('.canvas__field-main', { hasText: label }).click();
}

async function openPreview(page: Page): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Preview').click();
    await expect(page.locator('[data-builder-preview]')).toBeVisible({ timeout: 15_000 });
}

test('Builder — a half-built rule row leaves the other conditions working, and the preview says the rule is ignored', async ({ page }, info) => {
    test.setTimeout(180_000);
    const formId = await createForm(page, `Preview incomplete rule ${info.project.name} ${Date.now()}`);

    try {
        await addText(page, 'Trigger', 'trigger');
        await addText(page, 'Dependent', 'dependent');

        // Dependent shows only when Trigger says so — typed as text, the one-step way to a condition.
        await selectField(page, 'Dependent');
        await configTab(page, 'Advanced');
        await page.getByRole('button', { name: 'Edit as text' }).click();
        await page.getByLabel('Condition expression').fill("${trigger} = 'show'");
        await settled(page);

        // The engine works: Trigger is blank, so Dependent is not on the page.
        await openPreview(page);
        await expect(page.locator('[data-preview-field="trigger"]')).toBeVisible({ timeout: 15_000 });
        // The row's wrapper always renders; only the CONTROL inside it is gated on relevance (`FieldRow.vue`).
        await expect(page.locator('[data-preview-field="dependent"]').getByRole('textbox')).toHaveCount(0);

        // The seed: Conditional requiredness on Trigger, one condition added and left unfinished. Saved as it is.
        await selectField(page, 'Trigger');
        await configTab(page, 'Basics');
        await page.getByRole('group', { name: 'Requiredness' }).getByText('Conditional', { exact: true }).click();
        await page.getByRole('button', { name: 'Add condition' }).click();
        await settled(page);

        // The proof: Dependent is STILL hidden, and the chip under Trigger says why its rule is ignored.
        await openPreview(page);
        await expect(page.locator('[data-preview-field="trigger"]')).toBeVisible({ timeout: 15_000 });
        // The row's wrapper always renders; only the CONTROL inside it is gated on relevance (`FieldRow.vue`).
        await expect(page.locator('[data-preview-field="dependent"]').getByRole('textbox')).toHaveCount(0);
        await expect(page.locator('[data-preview-field="trigger"] .preview__issue')).toContainText('not finished');
        await assertClean(page, 'builder preview with an ignored half-built rule');
    } finally {
        await archive(page, formId);
    }
});

/*
 * M151 (`R-74c3cf35`) — Round 2's note `r2-c17`: "can we also use the middle section (structure and preview) to allow the
 * user to edit the label there already". `M150` gave Structure its half; this is Preview's. The Edit button on a question's
 * row opens its label in place, Enter saves it once, focus comes back to the button, and a reload keeps it.
 */
test('Builder — a question’s label edited in place in the preview, saved once and kept after a reload', async ({ page }, info) => {
    test.setTimeout(150_000);
    const formId = await createForm(page, `Preview label ${info.project.name} ${Date.now()}`);

    try {
        await showBuilderPane(page, 'fields');
        await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
        await settled(page);
        await openPreview(page);

        const row = page.locator('[data-preview-field]').first();
        await expect(row).toBeVisible({ timeout: 15_000 });
        await row.locator('[data-preview-edit-label]').click();
        const input = row.getByRole('textbox', { name: 'Question label' });
        await expect(input).toBeFocused();
        await input.fill('Asked in the preview');

        const patched = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes(`/forms/${formId}/fields/`) && r.ok());
        await input.press('Enter');
        await patched;
        await expect(row.locator('[data-preview-edit-label]')).toBeFocused();
        await expect(row).toContainText('Asked in the preview');
        await saved(page);

        await page.reload({ waitUntil: 'networkidle' });
        await openPreview(page);
        await expect(page.locator('[data-preview-field]').first()).toContainText('Asked in the preview', { timeout: 15_000 });
    } finally {
        await archive(page, formId);
    }
});

/*
 * M151 (`R-2baef8ea`) — Round 2's note `r2-c18`: "please include the preview section to be draggable. i mean, questions or
 * indicators must be draggable to sections, sequencing, etc." With a real mouse: a question dragged below another, one
 * dropped on a new section's placeholder, and — the form being stepped — one dropped on a page in the strip (`D105`),
 * which leaves the author on their page; then a keyboard move that keeps focus on the grip. All of it after a reload.
 */
async function drag(page: Page, from: Locator, to: () => Promise<{ x: number; y: number }>): Promise<void> {
    const grip = await from.locator('[data-preview-grip]').boundingBox();
    if (grip === null) throw new Error('the grip is not on screen');
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 12, { steps: 3 });
    await expect(page.locator('.builder-preview__sr')).toContainText('Dragging');
    const target = await to();
    await page.mouse.move(target.x, target.y, { steps: 8 });
    await page.mouse.up();
}

async function centreOf(locator: Locator, down = 0.5): Promise<{ x: number; y: number }> {
    const box = await locator.boundingBox();
    if (box === null) throw new Error('the target is not on screen');
    return { x: box.x + box.width / 2, y: box.y + box.height * down };
}

test('Builder — questions dragged in the preview: reordered, into a new section, onto a page in the strip, and by keyboard', async ({ page }, info) => {
    test.setTimeout(240_000);
    const formId = await createForm(page, `Preview drag ${info.project.name} ${Date.now()}`);

    try {
        for (let i = 0; i < 3; i++) {
            await showBuilderPane(page, 'fields');
            await page.locator('.palette').getByRole('button', { name: 'Text', exact: true }).click();
            await settled(page);
        }
        await openPreview(page);
        const rows = page.locator('[data-builder-preview] [data-preview-field]');
        await expect(rows).toHaveCount(3, { timeout: 15_000 });
        for (const [i, name] of ['Alpha', 'Beta', 'Gamma'].entries()) {
            await rows.nth(i).locator('[data-preview-edit-label]').click();
            const input = rows.nth(i).getByRole('textbox', { name: 'Question label' });
            await input.fill(name);
            await input.press('Enter');
            await expect(rows.nth(i)).toContainText(name);
        }
        await settled(page);
        const row = (name: string) => page.locator('[data-builder-preview] [data-preview-field]', { hasText: name });

        // Within the page: Alpha dropped below Gamma, written once, and shown there without waiting.
        let sent = reorderSent(page, formId);
        await drag(page, row('Alpha'), () => centreOf(row('Gamma'), 0.85));
        await sent;
        await expect(rows.nth(2)).toContainText('Alpha');
        await expect(rows.nth(0)).toContainText('Beta');

        // Into a section with no question yet, through its placeholder.
        await page.locator('[data-preview-add-section]').click();
        const placeholder = page.locator('[data-preview-empty-section]');
        await expect(placeholder).toBeVisible({ timeout: 15_000 });
        await saved(page);
        sent = reorderSent(page, formId);
        await drag(page, row('Alpha'), () => centreOf(placeholder));
        await sent;
        await expect(placeholder).toHaveCount(0);

        // The form is stepped, so the new section is its own page: dropping Beta on that page in the strip moves it there
        // and leaves the author where they were.
        const shownPage = page.locator('[data-builder-preview] [data-section]').first();
        const pageBefore = await shownPage.getAttribute('data-section-key');
        sent = reorderSent(page, formId);
        await drag(page, row('Beta'), () => centreOf(page.locator('[data-preview-drop-strip] li').nth(1)));
        await sent;
        await expect(shownPage).toHaveAttribute('data-section-key', pageBefore ?? '');
        await expect(row('Beta')).toHaveCount(0);
        await expect(page.locator('.builder-preview__sr')).toContainText('Dropped Beta');

        // On that page, by keyboard: Beta moved above Alpha, and focus stays on its grip through the rebuild.
        await page.locator('[data-preview-strip] label').nth(1).click();
        await expect(row('Beta')).toBeVisible();
        await row('Beta').locator('[data-preview-grip]').focus();
        await page.keyboard.press('Enter');
        await page.keyboard.press('ArrowUp');
        await expect(row('Alpha')).toHaveClass(/preview__row--drop-before/);
        sent = reorderSent(page, formId);
        await page.keyboard.press('Enter');
        await sent;
        await expect(row('Beta').locator('[data-preview-grip]')).toBeFocused();
        await saved(page);

        // Saved: Structure shows Gamma alone at the top and the section holding Beta then Alpha.
        await page.reload({ waitUntil: 'networkidle' });
        await showBuilderPane(page, 'canvas');
        await page.locator('.builder__centre-tabs').getByText('Structure').click();
        await expect(page.locator('[data-drop-group="ungrouped"] .canvas__field-label')).toHaveText(['Gamma']);
        await expect(page.locator('[data-drop-group]:not([data-drop-group="ungrouped"]) .canvas__field-label')).toHaveText(['Beta', 'Alpha']);
    } finally {
        await archive(page, formId);
    }
});
