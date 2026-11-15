import { expect, test, type Page } from '@playwright/test';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M239 (`nhete-browser-walk`) — one NHETE indicator block, clicked into being in the real builder.
 *
 * `M237` built the whole module through the builder's own HTTP routes (`NheteBuilderRouteTest`), which proves the
 * server takes what the builder sends, never that a person can reach the controls that send it. This spec is that half,
 * for ONE indicator block, the unit the module repeats 35 times (`NhetePlan`'s `indicator-block` workaround):
 *   - a note carrying the indicator's details as content blocks (a heading and a paragraph);
 *   - four dropdowns — "keep" with three typed choices, and three more (Yes and No);
 *   - a comment shown when "keep" is "change" (the condition editor), and required then too ("Required when…").
 * Then the preview proves the rules work: the comment appears only on "change", marked required. A reload keeps it all.
 *
 * ── STATE: IT MAKES ITS OWN FORM, AND ARCHIVES IT ─────────────────────────────────────────────────────
 * `builder-content-axe.spec.ts`'s reason: every spec shares one seeded database across three viewport projects.
 */

// A control that cannot be found or operated fails in seconds, naming itself, instead of spending the whole budget.
test.use({ actionTimeout: 15_000 });

/** The autosave debounces 600 ms, so "All changes saved" read earlier than that is the PREVIOUS save's. */
async function settled(page: Page): Promise<void> {
    await page.waitForTimeout(800);
    await saved(page);
}

async function configTab(page: Page, name: string): Promise<void> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name, exact: true }).click();
}

/**
 * Add a question from the palette, then give it a label and a key so conditions can name it. A `format` is a variant of
 * the palette entry — the palette's Text is a short text, and Long text is its Text format on the Basics tab.
 */
async function add(page: Page, type: string, label: string, key: string, format?: { group: string; option: string }): Promise<void> {
    await showBuilderPane(page, 'fields');
    await page.locator('.palette').getByRole('button', { name: type, exact: true }).click();
    await settled(page);

    // Adding selects the new question, so the config panel is already on it.
    await configTab(page, 'Basics');
    if (format !== undefined) {
        await page.getByRole('group', { name: format.group }).getByText(format.option, { exact: true }).click();
        await settled(page);
    }
    await page.getByLabel('Label', { exact: true }).fill(label);
    await configTab(page, 'Advanced');
    await page.getByLabel('Field key', { exact: true }).fill(key);
    await settled(page);
}

async function openPreview(page: Page): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Preview').click();
    await expect(page.locator('[data-builder-preview]')).toBeVisible({ timeout: 15_000 });
}

const KEEP_CHOICES = [
    ['yes', 'Keep it'],
    ['change', 'Change it'],
    ['no', 'Drop it'],
] as const;

test('Builder — one NHETE indicator block clicked into being: note content, four dropdowns, and a comment shown and required on "change"', async ({ page }, info) => {
    test.setTimeout(300_000);
    const formId = await createForm(page, `NHETE indicator block ${info.project.name} ${Date.now()}`);

    try {
        // The indicator's details: a note whose content is a heading and a paragraph.
        await add(page, 'Note / label', 'Indicator 1 details', 'i1_details');
        await configTab(page, 'Content');
        const editor = page.locator('.cbe');
        const blocks = editor.getByRole('group', { name: 'Add a block' });
        await blocks.getByRole('button', { name: 'Heading' }).click();
        await editor.getByLabel('Heading text').fill('I1 · Households with safe water');
        await blocks.getByRole('button', { name: 'Paragraph' }).click();
        await editor.getByLabel('Text', { exact: true }).fill('The share of households whose main water source is safe.');
        await expect(editor.locator('[data-block-type]')).toHaveCount(2);
        await settled(page);

        // "Keep this indicator?" — three choices typed one by one.
        await add(page, 'Dropdown', 'Keep this indicator?', 'i1_keep');
        await configTab(page, 'Options');
        for (const [i, [value, label]] of KEEP_CHOICES.entries()) {
            await page.getByRole('button', { name: 'Add option' }).click();
            await page.getByLabel(`Option ${i + 1} value`).fill(value);
            await page.getByLabel(`Option ${i + 1} label`).fill(label);
        }
        await settled(page);

        // Three more dropdowns, as the indicator table has (scope, data source, frequency): Yes and No each.
        for (const [label, key] of [
            ['Is the scope right?', 'i1_scope'],
            ['Is the data source right?', 'i1_source'],
            ['Is the frequency right?', 'i1_frequency'],
        ] as const) {
            await add(page, 'Dropdown', label, key);
            await configTab(page, 'Options');
            await page.locator('[data-choices-yes-no]').click();
            await settled(page);
        }

        // The comment: shown when "keep" is "change", and required then too.
        await add(page, 'Text', 'What should change?', 'i1_comment', { group: 'Text format', option: 'Long text' });
        await configTab(page, 'Advanced');
        await page.getByRole('button', { name: 'Edit as text' }).click();
        await page.getByLabel('Condition expression').fill("${i1_keep} = 'change'");
        await settled(page);
        await configTab(page, 'Basics');
        await page.getByRole('group', { name: 'Requiredness' }).getByText('Conditional', { exact: true }).click();
        await page.getByRole('button', { name: 'Add condition' }).click();
        await page.getByLabel('Rule 1 compared field').selectOption('i1_keep');
        await page.getByLabel('Rule 1 operator').selectOption('eq');
        await page.getByLabel('Rule 1 value').fill('change');
        await settled(page);

        // The preview: the comment is not on the page until "change" is picked, and then it is required.
        await openPreview(page);
        const keep = page.locator('[data-preview-field="i1_keep"]').locator('select');
        const comment = page.locator('[data-preview-field="i1_comment"]');
        await expect(keep).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-preview-field="i1_details"]')).toContainText('I1 · Households with safe water');
        await expect(comment.locator('textarea')).toHaveCount(0);
        await keep.selectOption('change');
        await expect(comment.locator('textarea')).toBeVisible();
        await expect(comment).toContainText('(required)');
        await keep.selectOption('yes');
        await expect(comment.locator('textarea')).toHaveCount(0);

        // And it was all saved: after a reload the block is whole.
        await page.reload({ waitUntil: 'networkidle' });
        await openPreview(page);
        await expect(page.locator('[data-preview-field]')).toHaveCount(6, { timeout: 15_000 });
        await expect(page.locator('[data-preview-field="i1_keep"] option:not([value=""])')).toHaveText(KEEP_CHOICES.map(([, label]) => label));
    } finally {
        await archive(page, formId);
    }
});
