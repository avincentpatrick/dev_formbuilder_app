import { expect, test, type Page } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

/*
 * M165 (`R-7173c02c`, `D106`) — a filled paper copy sent from the public link, and the staff list that receives it.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE UPLOAD ITSELF IS ANSWERED IN THE BROWSER (`page.route`), NEVER BY THE SERVER. CI's e2e job runs
 * `QUEUE_CONNECTION=sync`, so a real upload would run the reading job inline against the real provider — the reason
 * `ocr-review-axe.spec.ts` and `ocr-scans-upload.spec.ts` never send one either. The spam check before it IS real:
 * the browser mints and solves one, which is the half of the path a mock would hide. The staff half reads the guest
 * scan `E2eSeeder::seedOcrGuestScan()` wrote, so this spec creates nothing.
 *
 * M166 (`D116`): the read-back the sender's page polls is answered in the browser too — a copy that stays "reading", and
 * one read from the seeded form's own question keys — so the wait screen and the checking screen are scanned without a
 * reading job ever running.
 */

const themes = ['light', 'dark'] as const;

// A 1×1 PNG — enough for a real preview to decode, so axe and the overflow check see a loaded image.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

test.describe('the public link', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    async function openForm(page: Page): Promise<void> {
        await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Clinic Intake', level: 1 }).waitFor({ state: 'visible', timeout: 15_000 });
    }

    /** Answer the upload in the browser with a reference and a paper token, and record the spam-check header it carried. */
    async function answerUpload(page: Page, sent: string[]): Promise<void> {
        await page.route('**/api/v1/public/f/*/scans', async (route) => {
            sent.push(route.request().headers()['x-meridian-challenge'] ?? '');
            await route.fulfill({
                status: 202,
                contentType: 'application/json',
                body: JSON.stringify({ data: { reference: 'AB12-CD34', paper_token: 'paper-e2e' } }),
            });
        });
    }

    async function chooseAndSend(page: Page, theme: string): Promise<void> {
        const open = page.getByRole('button', { name: 'Filled in a paper copy? Upload it instead' });
        await expect(open).toHaveAttribute('aria-expanded', 'false');
        await open.click();
        await expect(open).toHaveAttribute('aria-expanded', 'true');

        await page.getByLabel('Pages of your paper form').setInputFiles([
            { name: 'paper-page-1.png', mimeType: 'image/png', buffer: PNG },
            { name: 'paper-page-2.png', mimeType: 'image/png', buffer: PNG },
        ]);
        await expect(page.getByRole('list', { name: '2 pages chosen' }).getByRole('listitem')).toHaveCount(2);

        await assertClean(page, `public paper copy, chosen (${theme})`);
        await page.getByRole('button', { name: 'Send paper copy' }).click();
    }

    for (const theme of themes) {
        test(`a filled paper copy, sent, waited on and left for staff, with its reference (${theme})`, async ({ page }) => {
            const sent: string[] = [];
            await answerUpload(page, sent);
            // M166 — the copy never finishes reading here, so the sender takes the way out the wait always offers.
            await page.route('**/api/v1/public/paper/**', (route) =>
                route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { status: 'reading' } }) }),
            );

            await openForm(page);
            await forceTheme(page, theme);
            await chooseAndSend(page, theme);

            await expect(page.getByRole('heading', { name: 'Reading your paper form…' })).toBeVisible({ timeout: 30_000 });
            await assertClean(page, `public paper copy, being read (${theme})`);
            await page.getByRole('button', { name: 'Leave it for our staff instead' }).click();

            await expect(page.getByRole('heading', { name: 'Your paper form was received. Staff will check it and enter your answers.' })).toBeVisible();
            await expect(page.getByText('Reference: AB12-CD34')).toBeVisible();
            // The check was minted and solved for real, and travelled as the header the server requires.
            expect(sent).toHaveLength(1);
            expect(JSON.parse(Buffer.from(sent[0], 'base64').toString()).signature).toBeTruthy();

            await assertClean(page, `public paper copy, received (${theme})`);
        });

        test(`a read paper copy reopens the form filled in, each unsure answer noted, for its sender to check (${theme})`, async ({ page }) => {
            // The keys are the seeded form's own, read from the real schema rather than written here.
            const schemaRead = page.waitForResponse(
                (response) => /\/api\/v1\/public\/f\/[^/]+$/.test(new URL(response.url()).pathname) && response.request().method() === 'GET',
            );
            await answerUpload(page, []);

            await openForm(page);
            const schema = (await (await schemaRead).json()) as { data: { version: { schema: { fields: { key: string; field_type: string }[] } } } };
            const fields = schema.data.version.schema.fields;
            const name = fields.find((field) => field.field_type === 'short_text')!.key;
            const age = fields.find((field) => field.field_type === 'integer')!.key;

            const meta = { tier: null, confidence: null, text: null, page: 1, reason: null, ticks: false, inked: false, repaired: false };
            await page.route('**/api/v1/public/paper/*/*/pages/*', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: PNG }));
            await page.route('**/api/v1/public/paper/*/paper-e2e', (route) =>
                route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        data: {
                            status: 'read',
                            version_id: 'any',
                            answers: { [name]: 'Ada Lovelace' },
                            fields: {
                                [name]: { ...meta, state: 'read', tier: 'review', confidence: 78, text: 'Ada Lovelace', carried: true },
                                [age]: { ...meta, state: 'unreadable', carried: false },
                            },
                            pages: [{ number: 1, url: '/api/v1/public/paper/s/paper-e2e/pages/1', mime: 'image/png', servable: true }],
                        },
                    }),
                }),
            );

            await forceTheme(page, theme);
            await chooseAndSend(page, theme);

            await expect(page.locator('[data-paper-review]')).toBeVisible({ timeout: 30_000 });
            await expect(page.locator('[data-paper-note]')).toHaveText([
                'Please check this answer. We may have read it wrong.',
                'We could not read this answer. Please fill it in.',
            ]);
            await expect(page.locator(`#field-${name} input`).first()).toHaveValue('Ada Lovelace');
            // Neither the upload nor a second way to keep the answers is offered on the copy being checked.
            await expect(page.getByRole('button', { name: 'Filled in a paper copy? Upload it instead' })).toHaveCount(0);

            // M168 (`R-17caf80e`, the user at M166's check: "its better if the preview is on the left … side by side"): the
            // scanned pages are shown, never folded — the LEFT half beside the form once both have room (64rem), at equal
            // widths ("50/50"), and above the form below that. Read from the boxes the browser drew.
            await expect(page.getByRole('region', { name: 'Page 1 of the scan' })).toBeVisible();
            const paper = (await page.locator('[data-paper-pages]').boundingBox())!;
            const form = (await page.locator('.session-body__form').boundingBox())!;
            if (page.viewportSize()!.width >= 1024) {
                expect(paper.x + paper.width, 'the paper is left of the form').toBeLessThanOrEqual(form.x);
                expect(Math.abs(paper.width - form.width) / form.width, 'the paper and the form are equal halves').toBeLessThan(0.1);
                // …of the screen, not of the 44rem reading column ("increase the size of the preview").
                expect(paper.width / page.viewportSize()!.width, 'the paper takes about half the screen').toBeGreaterThan(0.4);
            } else {
                expect(paper.y + paper.height, 'the paper stacks above the form').toBeLessThanOrEqual(form.y);
            }
            await assertClean(page, `public paper copy, being checked (${theme})`);
        });
    }
});

test.describe('the staff list', () => {
    async function openScans(page: Page): Promise<void> {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Clinic Intake', exact: true }).first().click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });

        await page.getByRole('navigation', { name: 'Clinic Intake' }).getByRole('link', { name: 'Responses' }).click();
        await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/submissions$/, { timeout: 30_000 });

        await page.getByRole('button', { name: 'Scan paper forms' }).click();
        await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
    }

    for (const theme of themes) {
        test(`a guest's scan is marked and found by its reference however it is typed (${theme})`, async ({ page }) => {
            await openScans(page);
            await forceTheme(page, theme);

            const guestRow = page.locator('.scans__row', { hasText: 'From the public link' });
            await expect(guestRow).toHaveCount(1);
            await expect(guestRow).toContainText('Reference GQ7K-4M2X');

            const search = page.getByRole('searchbox', { name: 'Find a scan by its reference' });
            await search.fill('gq7k 4m2x');
            await search.press('Enter');
            await page.waitForURL(/reference=/, { timeout: 30_000 });

            await expect(page.locator('.scans__row')).toHaveCount(1);
            await expect(page.locator('.scans__row')).toContainText('From the public link');

            await assertClean(page, `scans page, found by reference (${theme})`);

            await page.getByRole('button', { name: 'Show all scans' }).click();
            await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
            await expect(page.locator('.scans__row', { hasText: 'Ready to review' })).toHaveCount(1);
        });
    }
});
