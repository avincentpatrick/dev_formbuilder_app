import { test, expect, type Page } from '@playwright/test';
import { assertClean } from './support/axe';
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

async function createForm(page: Page, title: string): Promise<string> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'New form' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Title').fill(title);
    await dialog.getByRole('button', { name: 'Create form' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/builder$/, { timeout: 30_000 });

    return /\/forms\/([0-9a-f-]{36})\/builder$/.exec(page.url())![1];
}

async function archive(page: Page, formId: string): Promise<void> {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post(`/forms/${formId}/archive`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } });
    expect(response.status(), 'the form this spec made is archived again').toBeLessThan(400);
}

async function saved(page: Page): Promise<void> {
    await expect(page.getByText('All changes saved').first()).toBeVisible({ timeout: 20_000 });
}

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
        await page.keyboard.press('Enter');
        await expect(page.locator('[data-drop-group="ungrouped"] [data-field-uid]')).toHaveCount(1);
        await expect(page.locator('[data-section-empty]')).toBeVisible();
        await saved(page);
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
