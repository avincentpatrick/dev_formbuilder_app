import { test, expect, type Page, type Response } from '@playwright/test';
import { archive, createForm, saved } from './support/builder';
import { showBuilderPane } from './support/navigate';

/*
 * M170 (`R-bfa3eb93`, `R-7ebfb08e`) — the user's urgent report, 2026-10-14: "The autosave problem is in the form builder.
 * For a Whole Number question, the settings on the Basic, Validation and Advanced tabs don't save. … confirm that changing
 * it starts a save, that the server keeps the value, and that it's still there after a plain page refresh without clicking
 * anything else first."
 *
 * Each case changes ONE setting and reloads AT ONCE — no wait for the save, no other click — then opens the question again
 * and reads the setting back. Before M170 a change waited 600 ms for its save and nothing sent it when the page went, so
 * the reload served the old value while the toolbar already said "All changes saved". Nor can a flush on leaving close it
 * on its own: Chrome asks for the new page BEFORE the old one's `pagehide`, so the reload can be served before any save
 * sent then has landed. Only the builder putting the unsent edit back on load makes a plain refresh keep it every time.
 *
 * ⚠️ IT WAITS ON THE PATCH, NEVER ON THE LABEL ALONE: "All changes saved" is also what an untouched builder says.
 *
 * Desktop only for the per-setting cases — the save path is the same at every width and the panes switcher is not — and
 * one case at phone width. It makes its own form and archives it (M139's reason: one seeded database, three projects).
 */

const QUESTION = 'Household size';

async function openQuestion(page: Page, label = QUESTION): Promise<void> {
    await showBuilderPane(page, 'canvas');
    await page.locator('.builder__centre-tabs').getByText('Structure').click();
    await page.locator('.canvas__field-main', { hasText: label }).first().click();
}

async function tab(page: Page, name: string): Promise<void> {
    await showBuilderPane(page, 'settings');
    await page.getByRole('tab', { name, exact: true }).click();
}

/** The next PATCH of a question on this form whose body satisfies `carries` — start waiting BEFORE the change. */
function patched(page: Page, formId: string, carries: (body: Record<string, unknown>) => boolean): Promise<Response> {
    return page.waitForResponse(
        (response) => {
            const request = response.request();
            if (request.method() !== 'PATCH' || !response.url().includes(`/forms/${formId}/fields/`)) return false;
            const body = request.postDataJSON() as Record<string, unknown> | null;

            return body !== null && carries(body);
        },
        { timeout: 20_000 },
    );
}

/** A Number from the palette is a Whole number; it is labelled and saved before any case starts. */
async function addWholeNumber(page: Page, formId: string): Promise<void> {
    await showBuilderPane(page, 'fields');
    await page.locator('.palette').getByRole('button', { name: 'Number', exact: true }).click();
    await page.waitForTimeout(800);
    await saved(page);

    await tab(page, 'Basics');
    await expect(page.locator('[data-field-type-control]')).toContainText('Whole number');
    const sent = patched(page, formId, (body) => body.label === QUESTION);
    await page.getByLabel('Label', { exact: true }).fill(QUESTION);
    expect((await sent).ok(), 'the question is labelled before any case starts').toBe(true);
}

/** The plain refresh the user asked for: straight after the change, with nothing clicked in between. */
async function refreshNow(page: Page): Promise<void> {
    await page.reload({ waitUntil: 'load' });
}

interface Setting {
    name: string;
    tab: 'Basics' | 'Validation' | 'Advanced';
    change: (page: Page) => Promise<void>;
    check: (page: Page) => Promise<void>;
}

const group = (page: Page) => page.getByRole('group', { name: 'Requiredness' });
const checks = (page: Page) => page.locator('.config__checks');

/*
 * In order, because two of them meet: "Allow negative numbers" off writes a minimum of 0 as rule 1, so the Validation cases
 * add rules 2 and 3 — and a custom minimum would disable the toggle, which is why the toggle goes first.
 */
const SETTINGS: Setting[] = [
    {
        name: 'Help text',
        tab: 'Basics',
        change: (page) => page.getByLabel('Help text', { exact: true }).fill('Count everyone who sleeps here.'),
        check: (page) => expect(page.getByLabel('Help text', { exact: true })).toHaveValue('Count everyone who sleeps here.'),
    },
    {
        name: 'Placeholder',
        tab: 'Basics',
        change: (page) => page.getByLabel('Placeholder', { exact: true }).fill('e.g. 4'),
        check: (page) => expect(page.getByLabel('Placeholder', { exact: true })).toHaveValue('e.g. 4'),
    },
    {
        name: 'Requiredness',
        tab: 'Basics',
        change: (page) => group(page).getByText('Required', { exact: true }).click(),
        check: (page) => expect(group(page).getByRole('radio', { name: 'Required', exact: true })).toBeChecked(),
    },
    {
        name: 'Allow negative numbers',
        tab: 'Basics',
        change: (page) => page.getByText('Allow negative numbers', { exact: true }).click(),
        check: (page) => expect(page.getByRole('checkbox', { name: 'Allow negative numbers' })).not.toBeChecked(),
    },
    {
        name: 'a maximum value rule',
        tab: 'Validation',
        change: async (page) => {
            await page.getByRole('button', { name: 'Add rule' }).click();
            await page.getByLabel('Rule 2 check', { exact: true }).selectOption('max_value');
            await page.getByLabel('Rule 2 value', { exact: true }).fill('20');
        },
        check: async (page) => {
            await expect(page.getByLabel('Rule 2 check', { exact: true })).toHaveValue('max_value');
            await expect(page.getByLabel('Rule 2 value', { exact: true })).toHaveValue('20');
        },
    },
    {
        name: "a rule's error message",
        tab: 'Validation',
        change: (page) => page.getByLabel('Rule 2 error message', { exact: true }).fill('No more than 20 people.'),
        check: (page) => expect(page.getByLabel('Rule 2 error message', { exact: true })).toHaveValue('No more than 20 people.'),
    },
    {
        name: 'an expression rule',
        tab: 'Validation',
        change: async (page) => {
            await page.getByRole('button', { name: 'Add rule' }).click();
            await page.getByLabel('Rule 3 type', { exact: true }).selectOption('expression');
            await page.getByLabel('Rule 3 expression', { exact: true }).fill('. <= 15');
        },
        check: (page) => expect(page.getByLabel('Rule 3 expression', { exact: true })).toHaveValue('. <= 15'),
    },
    {
        name: 'Field key',
        tab: 'Advanced',
        change: (page) => page.getByLabel('Field key', { exact: true }).fill('household_size'),
        check: (page) => expect(page.getByLabel('Field key', { exact: true })).toHaveValue('household_size'),
    },
    {
        name: 'Default value',
        tab: 'Advanced',
        change: (page) => page.getByLabel('Default value', { exact: true }).fill('4'),
        check: (page) => expect(page.getByLabel('Default value', { exact: true })).toHaveValue('4'),
    },
    {
        name: 'Contains personal data (PII)',
        tab: 'Advanced',
        change: (page) => checks(page).getByText('Contains personal data (PII)', { exact: true }).click(),
        check: (page) => expect(checks(page).getByRole('checkbox', { name: 'Contains personal data (PII)' })).toBeChecked(),
    },
    {
        name: 'Sensitive',
        tab: 'Advanced',
        change: (page) => checks(page).getByText('Sensitive', { exact: true }).click(),
        check: (page) => expect(checks(page).getByRole('checkbox', { name: 'Sensitive', exact: true })).toBeChecked(),
    },
    {
        name: 'Indexed for reporting, with its type',
        tab: 'Advanced',
        change: async (page) => {
            await checks(page).getByText('Indexed for reporting', { exact: true }).click();
            await page.getByLabel('Indexed data type', { exact: true }).selectOption('number');
        },
        check: async (page) => {
            await expect(checks(page).getByRole('checkbox', { name: 'Indexed for reporting' })).toBeChecked();
            await expect(page.getByLabel('Indexed data type', { exact: true })).toHaveValue('number');
        },
    },
    {
        name: 'Label',
        tab: 'Basics',
        change: (page) => page.getByLabel('Label', { exact: true }).fill(`${QUESTION} today`),
        check: (page) => expect(page.getByLabel('Label', { exact: true })).toHaveValue(`${QUESTION} today`),
    },
];

test('Builder — every Whole Number setting on Basics, Validation and Advanced is still there after a refresh straight after the change', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the save path is the same at every width; the phone case below covers 375px');
    test.setTimeout(300_000);
    const formId = await createForm(page, `Settings refresh ${info.project.name} ${Date.now()}`);

    try {
        await addWholeNumber(page, formId);
        // A control that never appears must fail its own case in seconds, not hold the run to the test's timeout.
        page.setDefaultTimeout(15_000);
        page.setDefaultNavigationTimeout(45_000);

        const lost: string[] = [];
        for (const setting of SETTINGS) {
            await openQuestion(page);
            await tab(page, setting.tab);
            const changed = await setting.change(page).then(() => true, () => false);
            await refreshNow(page);
            if (!changed) {
                lost.push(`${setting.tab} → ${setting.name} (could not be changed)`);
                continue;
            }

            await openQuestion(page);
            await tab(page, setting.tab);
            await setting.check(page).catch(() => lost.push(`${setting.tab} → ${setting.name}`));
        }

        // Every setting is read back before the verdict, so one run names every lost setting rather than the first.
        expect(lost, 'settings lost by a refresh straight after the change').toEqual([]);

        // And the server holds them: a second reload, long after every save, reads the last one back too.
        await page.waitForTimeout(1_000);
        await page.reload({ waitUntil: 'networkidle' });
        await openQuestion(page);
        await tab(page, 'Basics');
        await expect(page.getByLabel('Label', { exact: true })).toHaveValue(`${QUESTION} today`);
        await saved(page);
    } finally {
        await archive(page, formId);
    }
});

test('Builder — a setting changed on a phone is still there after a refresh straight after the change', async ({ page }, info) => {
    test.skip(info.project.name !== 'mobile', 'the desktop case above covers every setting');
    test.setTimeout(150_000);
    const formId = await createForm(page, `Settings refresh ${info.project.name} ${Date.now()}`);

    try {
        await addWholeNumber(page, formId);
        await openQuestion(page);
        await tab(page, 'Basics');
        await page.getByLabel('Help text', { exact: true }).fill('Count everyone who sleeps here.');
        await refreshNow(page);

        await openQuestion(page);
        await tab(page, 'Basics');
        await expect(page.getByLabel('Help text', { exact: true })).toHaveValue('Count everyone who sleeps here.');
    } finally {
        await archive(page, formId);
    }
});
