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
 * `M240` (`builder-copy-section`, `D224`, `D225`) then copies the block the two ways NHETE's other 34 would be made: its
 * six questions ticked and Duplicated (keys i1 → i2, the copied comment answering to the copied "keep"; one Undo and one
 * Redo), and, moved into a section, the section Duplicated (i2 → i3, the condition naming i3_keep after a reload).
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

async function structure(page: Page): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Structure').click();
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

        // M245: the indicator's two items as a list, and "More about this indicator" as a details block whose paragraph
        // marks a glossary word. The Content tab names the word until the glossary has it.
        await blocks.getByRole('button', { name: 'List' }).click();
        await editor.getByLabel('Items').fill('Piped water\nA protected well');
        await blocks.getByRole('button', { name: 'Details' }).click();
        const details = editor.locator('[data-block-type="details"]');
        await details.getByLabel('Summary').fill('More about this indicator');
        await details.getByRole('group', { name: 'Add inside block 4' }).getByRole('button', { name: 'Paragraph' }).click();
        await details.getByLabel('Text', { exact: true }).fill('Counted by the [[I1]] survey.');
        await expect(details.locator('[data-glossary-missing]')).toContainText('I1');
        await settled(page);

        // The glossary, under Form settings: the note's word now finds its term.
        await page.getByRole('button', { name: 'Form settings' }).click();
        const settingsDialog = page.getByRole('dialog', { name: 'Form settings' });
        await settingsDialog.getByRole('group', { name: 'Settings section' }).getByRole('button', { name: 'Glossary', exact: true }).click();
        await settingsDialog.locator('input[data-glossary-new-term], [data-glossary-new-term] input').fill('I1');
        await settingsDialog.locator('textarea[data-glossary-new-definition], [data-glossary-new-definition] textarea').fill('Households with safe water.');
        await settingsDialog.getByRole('button', { name: 'Add term' }).click();
        await expect(settingsDialog.getByRole('status')).toHaveText('Added I1.');
        await page.keyboard.press('Escape');
        await expect(settingsDialog).toHaveCount(0);
        await expect(details.locator('[data-glossary-missing]')).toHaveCount(0);

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
        // M245: the list, the details block opening, and the marked word's meaning in a dialog.
        const preview = page.locator('[data-preview-field="i1_details"]');
        await expect(preview.locator('ul > li')).toHaveText(['Piped water', 'A protected well']);
        await preview.getByRole('button', { name: 'More about this indicator' }).click();
        await preview.locator('[data-glossary-term="I1"]').click();
        await expect(page.getByRole('dialog', { name: 'I1' })).toContainText('Households with safe water.');
        await page.keyboard.press('Escape');
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
        // M245: the list, the details block and the glossary word were saved too.
        await expect(page.locator('[data-preview-field="i1_details"] ul > li')).toHaveCount(2);
        await expect(page.locator('[data-preview-field="i1_details"] [data-glossary-term="I1"]')).toHaveCount(1);

        // ── M240 (`builder-copy-section`, `D224`, `D225`) — the block copied whole, the way NHETE's 34 others would be ──
        // Tick its six questions and press Duplicate: the copies' keys count up (i1 → i2), and the copied comment answers
        // to the COPIED "keep", shown and required on "change" while the source's stays hidden.
        await structure(page);
        const topLevel = page.locator('[data-drop-group="ungrouped"] [data-field-uid]');
        await expect(topLevel).toHaveCount(6);
        for (let i = 0; i < 6; i++) await topLevel.nth(i).locator('[data-field-tick]').click();
        const bar = page.locator('[data-bulk-bar]');
        await expect(bar).toContainText('6 selected');
        await bar.locator('[data-bulk-duplicate]').click();
        await settled(page);
        await expect(topLevel).toHaveCount(12);
        await expect(bar).toContainText('6 selected'); // the ticks moved to the copies

        await openPreview(page);
        const keep2 = page.locator('[data-preview-field="i2_keep"]').locator('select');
        const comment2 = page.locator('[data-preview-field="i2_comment"]');
        await expect(keep2).toBeVisible({ timeout: 15_000 });
        await expect(comment2.locator('textarea')).toHaveCount(0);
        await keep2.selectOption('change');
        await expect(comment2.locator('textarea')).toBeVisible();
        await expect(comment2).toContainText('(required)');
        await expect(comment.locator('textarea')).toHaveCount(0); // i1_comment: its own "keep" is still unanswered

        // One Undo takes the whole copy away; Redo brings it back.
        await page.getByRole('button', { name: 'Undo' }).click();
        await settled(page);
        await expect(page.locator('[data-preview-field]')).toHaveCount(6);
        await page.getByRole('button', { name: 'Redo' }).click();
        await settled(page);
        await expect(page.locator('[data-preview-field]')).toHaveCount(12);

        // Duplicate section: the copied block moved into a section, then the section copied with it. Inside the copy the
        // comment's condition names the section copy's own "keep" (i2 → i3), and it all survives a reload.
        await structure(page);
        await page.getByRole('button', { name: 'Add section' }).click();
        await settled(page);
        for (let i = 6; i < 12; i++) await topLevel.nth(i).locator('[data-field-tick]').click();
        await expect(bar).toContainText('6 selected');
        await bar.getByRole('combobox', { name: 'Move to' }).selectOption({ index: 1 });
        await bar.getByRole('button', { name: 'Move', exact: true }).click();
        await settled(page);
        await expect(topLevel).toHaveCount(6);
        await page.locator('[data-section-duplicate]').first().click();
        await settled(page);

        await page.reload({ waitUntil: 'networkidle' });
        await structure(page);
        // A section's group: its head (`[data-section-uid]`) and, beside it, the list holding its questions.
        const sections = page.locator('.canvas__group').filter({ has: page.locator('[data-section-uid]') });
        await expect(sections).toHaveCount(2, { timeout: 15_000 });
        await expect(sections.nth(1).locator('.canvas__section-label')).toHaveText('New section (copy)');
        await expect(sections.nth(1).locator('[data-field-uid]')).toHaveCount(6);
        await sections.nth(1).locator('[data-field-uid]').filter({ hasText: 'What should change?' }).locator('.canvas__field-main').click();
        await configTab(page, 'Advanced');
        await expect(page.getByLabel('Field key', { exact: true })).toHaveValue('i3_comment');
        await page.getByRole('button', { name: 'Edit as text' }).click();
        await expect(page.getByLabel('Condition expression')).toHaveValue("${i3_keep} = 'change'");
    } finally {
        await archive(page, formId);
    }
});
