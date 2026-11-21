import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { assertClean, assertNoHorizontalOverflow, forceTheme } from './support/axe';

/*
 * M239 (`nhete-browser-walk`) — the NHETE pre-work module, walked by a respondent in a real browser.
 *
 * `NheteE2eSeeder` publishes the module (the Meridian-dialect import `M237` measured) at `/f/nhete`: 49 screens, one per
 * page, with save-and-resume on. CI's E2E job seeds it straight after `E2eSeeder`. This spec answers every page the way a
 * hurried respondent would — the FIRST choice everywhere and a few words in every box — so "keep" is Yes and every parts
 * page appears, then submits. At each of the three widths (the projects), and on every page:
 *   - no sideways scroll (`assertNoHorizontalOverflow`);
 *   - on the welcome, the first indicator, the 141-question parts and the red-flag pages, axe in light and dark;
 *   - "Save and finish later" on the first parts page, then the link opened in a FRESH browser, which must land on that
 *     page with the earlier answers still there;
 *   - the walk visits exactly the fixture's screens, in order, and the submission is accepted.
 * What it measures (boot, bytes, the biggest page) is printed as one `NHETE-METRICS` line per width, and attached.
 *
 * Every number here is measured against the same-shape fixture (`D221`), never the review copy's words.
 * `D223`: it runs on every CI run, at all three widths.
 */

// A control that cannot be operated fails in seconds, naming itself, instead of spending the whole budget retrying.
test.use({ storageState: { cookies: [], origins: [] }, actionTimeout: 15_000 });

interface Screen {
    key: string;
}

const FIXTURE = JSON.parse(readFileSync(join(process.cwd(), 'tests/fixtures/nhete/nhete-shape.json'), 'utf8')) as {
    screens: Screen[];
};
const SCREENS = FIXTURE.screens.map((s) => s.key);

/** The pages scanned with axe, by section key: the welcome, the first indicator table, the biggest parts table, red flags. */
const SCANNED: Record<string, string> = { welcome: 'welcome', mx_a: 'first indicator table', cp_d: 'parts, 141 questions', s8_rf: 'red flags' };

/** Where the respondent leaves and comes back: the first parts page, right after the first indicator page. */
const RESUME_AT = 'cp_a';

/** The biggest page: its field count is pinned, so a fixture or import change that shrinks it cannot pass unnoticed. */
const BIGGEST = { key: 'cp_d', rows: 141 };

const TEXT_ANSWER = 'A short answer for the walk.';

/**
 * M248 (`D232`, NHETE recommendation 6a) — "Until the closing date, a person can come back on the same device and change
 * answers." After Submit the walk changes the first open answer, on this screen, through "Change my answers".
 */
const CHANGE_AT = 's1a';
const CHANGED_ANSWER = 'Changed after sending.';

interface Todo {
    key: string;
    kind: 'radio' | 'checkbox' | 'select' | 'textarea' | 'text' | 'number' | 'date';
    value?: string;
}

function step(page: Page) {
    return page.locator('section[data-section]');
}

/** M248 — reopen the form's link on this device: it says the form was answered, and Change my answers opens the response. */
async function changeAnswers(page: Page): Promise<void> {
    await page.goto('/f/nhete');
    await expect(page.locator('[data-already-answered]'), 'the device remembers it answered').toBeVisible({ timeout: 60_000 });
    await page.getByRole('button', { name: 'Change my answers' }).click();
    await expect(page.locator('[data-amend-notice]')).toBeVisible({ timeout: 60_000 });
    await expect(step(page)).toHaveAttribute('data-section-key', 'welcome');
}

/** M248 — press Next until the page `target` is on screen, or (null) until there is no Next. Every answer is already there. */
async function walkTo(page: Page, target: string | null): Promise<void> {
    for (;;) {
        const key = await currentKey(page);
        if (key === target) return;
        if (await page.getByRole('button', { name: 'Next', exact: true }).count() === 0) {
            expect(target, `the walk reached the end without finding ${target}`).toBeNull();
            return;
        }
        await page.getByRole('button', { name: 'Next', exact: true }).click();
        await expect(step(page)).not.toHaveAttribute('data-section-key', key, { timeout: 30_000 });
    }
}

async function currentKey(page: Page): Promise<string> {
    return (await step(page).getAttribute('data-section-key')) ?? '';
}

/** Every question on the page that is showing and still blank, read in one pass so a 141-question page is one round trip. */
async function blanks(page: Page): Promise<Todo[]> {
    return step(page).evaluate((root) => {
        const out: Todo[] = [];
        root.querySelectorAll<HTMLElement>('[data-field-row]').forEach((row) => {
            const key = row.id.replace(/^field-/, '');
            const radios = [...row.querySelectorAll<HTMLInputElement>('input[type="radio"]')];
            if (radios.length > 0) {
                if (!radios.some((r) => r.checked)) out.push({ key, kind: 'radio' });
                return;
            }
            const checks = [...row.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')];
            if (checks.length > 0) {
                if (!checks.some((c) => c.checked)) out.push({ key, kind: 'checkbox' });
                return;
            }
            const select = row.querySelector<HTMLSelectElement>('select');
            if (select !== null) {
                const first = [...select.options].find((o) => o.value !== '' && !o.disabled);
                if (select.value === '' && first !== undefined) out.push({ key, kind: 'select', value: first.value });
                return;
            }
            const area = row.querySelector<HTMLTextAreaElement>('textarea');
            if (area !== null) {
                if (area.value === '' && !area.readOnly && !area.disabled) out.push({ key, kind: 'textarea' });
                return;
            }
            const input = row.querySelector<HTMLInputElement>('input:not([type="hidden"])');
            if (input !== null && input.value === '' && !input.readOnly && !input.disabled) {
                out.push({ key, kind: input.type === 'number' ? 'number' : input.type === 'date' ? 'date' : 'text' });
            }
        });
        return out;
    });
}

/** Answer the page, reading it again after each round: a parts page reveals questions as earlier ones are answered. */
async function answerPage(page: Page): Promise<number> {
    let answered = 0;
    for (let round = 0; round < 30; round++) {
        const todo = await blanks(page);
        if (todo.length === 0) return answered;
        for (const t of todo) {
            const row = page.locator(`[id="field-${t.key}"]`);
            // A choice's native input is visually hidden under the drawn circle or box, which takes the pointer: click the
            // label, as a person does, then confirm it took.
            if (t.kind === 'radio') {
                await row.locator('label.mds-radio').first().click();
                await expect(row.getByRole('radio').first()).toBeChecked();
            } else if (t.kind === 'checkbox') {
                await row.locator('label.mds-checkbox').first().click();
                await expect(row.getByRole('checkbox').first()).toBeChecked();
            }
            else if (t.kind === 'select') await row.locator('select').first().selectOption(t.value ?? '');
            else if (t.kind === 'textarea') await row.locator('textarea').first().fill(TEXT_ANSWER);
            else if (t.kind === 'number') await row.locator('input').first().fill('1');
            else if (t.kind === 'date') await row.locator('input').first().fill('2026-01-15');
            else await row.locator('input:not([type="hidden"])').first().fill(TEXT_ANSWER);
            answered++;
        }
    }
    throw new Error(`page ${await currentKey(page)} still had blank questions after 30 rounds of answering`);
}

/** Next, and fail naming the page and what it said if the page does not move. */
async function next(page: Page, from: string): Promise<string> {
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    try {
        await expect(step(page)).not.toHaveAttribute('data-section-key', from, { timeout: 30_000 });
    } catch {
        const said = await page.locator('[role="alert"]').allInnerTexts();
        throw new Error(`page ${from} did not move on Next; it says: ${said.join(' | ') || '(no alert)'}`);
    }
    return currentKey(page);
}

async function scanBothThemes(page: Page, key: string): Promise<void> {
    await assertClean(page, `NHETE ${SCANNED[key]} (light)`);
    await forceTheme(page, 'dark');
    await assertClean(page, `NHETE ${SCANNED[key]} (dark)`);
    await forceTheme(page, 'light');
}

test('NHETE — the whole module answered page by page, left and resumed, submitted, and changed after sending', async ({ page, browser }, info) => {
    // Measured locally (one worker, built assets): see the NHETE-METRICS line. The budget is several times that.
    test.setTimeout(20 * 60_000);
    const metrics: Record<string, unknown> = { project: info.project.name };

    // ── Boot ───────────────────────────────────────────────────────────────────────────────────────────────
    const started = Date.now();
    await page.goto('/f/nhete');
    await expect(step(page)).toHaveAttribute('data-section-key', 'welcome', { timeout: 60_000 });
    metrics.boot_ms = Date.now() - started;
    Object.assign(
        metrics,
        await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
            const res = performance.getEntriesByType('resource') as PerformanceResourceTiming[];
            const schema = res.filter((r) => r.name.includes('/api/v1/public/')).sort((a, b) => b.decodedBodySize - a.decodedBodySize)[0];
            return {
                dom_content_loaded_ms: Math.round(nav.domContentLoadedEventEnd),
                load_ms: Math.round(nav.loadEventEnd),
                document_bytes: nav.decodedBodySize,
                resource_bytes: res.reduce((sum, r) => sum + (r.decodedBodySize || 0), 0),
                transfer_bytes: nav.transferSize + res.reduce((sum, r) => sum + (r.transferSize || 0), 0),
                schema_bytes: schema?.decodedBodySize ?? null,
            };
        }),
    );

    // ── The walk ──────────────────────────────────────────────────────────────────────────────────────────
    let walker = page;
    let resumed: BrowserContext | null = null;
    // M248 (`D118` A) — the tab is closed once, on the first indicator page, and the link opened again in the same browser.
    let reopened = false;
    const visited: string[] = [];
    let remembered: { key: string; value: string } | null = null;
    let key = 'welcome';
    const walkStarted = Date.now();

    for (;;) {
        visited.push(key);
        await assertNoHorizontalOverflow(walker, `NHETE page ${key}`);

        if (key === BIGGEST.key) {
            const shown = Date.now();
            await expect(step(walker).locator('[data-field-row]')).toHaveCount(BIGGEST.rows);
            metrics.biggest_page_rows_ms = Date.now() - shown;
        }

        if (key === RESUME_AT && resumed === null) {
            // Leave on a fresh page, then come back in a new browser, as the link promises ("works on any device").
            const leaving = walker;
            await leaving.getByRole('button', { name: 'Save and finish later' }).click();
            await leaving.getByRole('button', { name: 'Copy link' }).waitFor({ state: 'visible', timeout: 30_000 });
            const link = await leaving.getByLabel('Your resume link').inputValue();
            const path = new URL(link).pathname;
            expect(path, 'the resume link is a /f/resume/{token} link').toMatch(/^\/f\/resume\/[^/]+$/);

            // The link names APP_URL's host; open its path on the server this run is talking to.
            resumed = await browser.newContext({ viewport: leaving.viewportSize(), reducedMotion: 'reduce' });
            walker = await resumed.newPage();
            await walker.goto(new URL(path, leaving.url()).toString());
            await expect(step(walker), 'the resume link lands on the page it was saved on').toHaveAttribute('data-section-key', RESUME_AT, {
                timeout: 60_000,
            });

            // The earlier answers came back with it.
            expect(remembered).not.toBeNull();
            await walker.getByRole('button', { name: 'Back', exact: true }).click();
            await expect(step(walker)).not.toHaveAttribute('data-section-key', RESUME_AT, { timeout: 30_000 });
            await expect(walker.locator(`[id="field-${remembered!.key}"] select`).first()).toHaveValue(remembered!.value);
            await walker.getByRole('button', { name: 'Next', exact: true }).click();
            await expect(step(walker)).toHaveAttribute('data-section-key', RESUME_AT, { timeout: 30_000 });
            await leaving.close();
        }

        const filling = Date.now();
        const answered = await answerPage(walker);
        if (key === BIGGEST.key) {
            metrics.biggest_page_answered = answered;
            metrics.biggest_page_fill_ms = Date.now() - filling;
        }
        if (remembered === null && key.startsWith('mx_')) {
            const select = walker.locator('section[data-section] [data-field-row] select').first();
            const row = walker.locator('section[data-section] [data-field-row]', { has: walker.locator('select') }).first();
            remembered = { key: ((await row.getAttribute('id')) ?? '').replace(/^field-/, ''), value: await select.inputValue() };
        }

        if (!reopened && remembered !== null) {
            // M248 (`D118` A, `R-8881210a`) — close the tab and open the form's link again in the same browser. The
            // answers are on this device, behind a choice asked before any of them appears; Continue brings back this page.
            reopened = true;
            await expect(walker.locator('.saved-indicator')).toHaveText('Saved', { timeout: 30_000 });
            const closing = walker;
            walker = await closing.context().newPage();
            await closing.close();
            await walker.goto('/f/nhete');
            await expect(walker.locator('[data-continue-choice]'), 'reopening the link asks before showing any answer').toBeVisible({ timeout: 60_000 });
            await expect(step(walker)).toHaveCount(0);
            await walker.getByRole('button', { name: 'Continue where you left off' }).click();
            await expect(step(walker), 'Continue lands on the page left').toHaveAttribute('data-section-key', key, { timeout: 60_000 });
            await expect(walker.locator(`[id="field-${remembered.key}"] select`).first()).toHaveValue(remembered.value);
        }

        if (key in SCANNED) await scanBothThemes(walker, key);

        if (await walker.getByRole('button', { name: 'Next', exact: true }).count() === 0) break;
        key = await next(walker, key);
    }

    expect(visited, 'the walk visits every screen of the module, in order').toEqual(SCREENS);

    // ── Submit ────────────────────────────────────────────────────────────────────────────────────────────
    const submitting = Date.now();
    await walker.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(walker.locator('h1.confirmation__title')).toBeVisible({ timeout: 120_000 });
    metrics.submit_ms = Date.now() - submitting;
    metrics.walk_ms = Date.now() - walkStarted;
    metrics.pages = visited.length;

    // ── Change after sending (M248, `D232`) ────────────────────────────────────────────────────────────────
    // The thank-you page offers "Change my answers" and its link; reopening the form's link on this device says it was
    // answered and offers the change too. Change the first open answer, send it, and find it kept.
    const changing = Date.now();
    await expect(walker.locator('[data-amend-link]')).toBeVisible();
    expect(new URL(await walker.getByLabel('Your link to change your answers').inputValue()).pathname).toMatch(/^\/f\/amend\/[^/]+$/);

    await changeAnswers(walker);
    await walkTo(walker, CHANGE_AT);
    await step(walker).locator('textarea').first().fill(CHANGED_ANSWER);
    await walkTo(walker, null);
    await walker.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(walker.locator('h1.confirmation__title')).toHaveText('Your changes were saved.', { timeout: 120_000 });

    await changeAnswers(walker);
    await walkTo(walker, CHANGE_AT);
    await expect(step(walker).locator('textarea').first(), 'the changed answer was kept').toHaveValue(CHANGED_ANSWER);
    metrics.change_ms = Date.now() - changing;

    const line = JSON.stringify(metrics);
    console.log(`NHETE-METRICS ${line}`);
    await info.attach('nhete-metrics', { body: line, contentType: 'application/json' });
    await resumed?.close();
});
