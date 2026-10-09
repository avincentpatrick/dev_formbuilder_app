import { test, expect, type Page } from '@playwright/test';
import { archive, createForm } from './support/builder';

/*
 * M155 (`R-77a29731`) — the photo and file upload control hides three things from sight and keeps them for a screen
 * reader: the "(required)" beside the question, a "Remove {name}" inside every ✕, and the announcement after each
 * upload. All three used a class, `mds-visually-hidden`, that no stylesheet defines, so all three were drawn on
 * screen as ordinary text — on the staff encode page and in the guest runtime, wherever a question takes a file.
 * `M154` found its scans-page copy only by looking at a screenshot: axe, happy-dom and every test passed.
 *
 * So this spec measures the one thing that tells hidden from shown — each node's rendered box — on a real page.
 *
 * ── STATE ──────────────────────────────────────────────────────────────────────────────────────────────
 * No seeded form publishes a file question (`Media Builder Demo` is a DRAFT other specs open as one), so the spec
 * makes its own form, adds a required photo question and publishes it through the builder's own routes, then
 * archives it again (`support/builder.ts`'s reason: one seeded database across three viewport projects).
 */

// A 1×1 PNG: a real image, so the control's preview decodes whether or not the upload lands.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function xsrf(page: Page): Promise<Record<string, string>> {
    const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** A published form whose one question is a required photo — made through the routes the builder itself calls. */
async function publishedPhotoForm(page: Page, title: string): Promise<string> {
    const formId = await createForm(page, title);

    const created = await page.request.post(`/forms/${formId}/fields`, {
        headers: await xsrf(page),
        data: { field_type: 'image_capture', section_id: null },
    });
    expect(created.status(), 'the photo question is added').toBeLessThan(400);
    const field = await created.json();

    const saved = await page.request.patch(`/forms/${formId}/fields/${field.id}`, {
        headers: await xsrf(page),
        data: {
            key: field.key,
            label: 'Photo of the site',
            is_required: 'required',
            config: field.config ?? {},
            validations: [],
            version: field.version,
        },
    });
    expect(saved.status(), 'the photo question is made required').toBeLessThan(400);

    const published = await page.request.post(`/forms/${formId}/publish`, { headers: await xsrf(page), data: {} });
    expect(published.status(), 'the form is published').toBeLessThan(400);

    return formId;
}

/** Hidden from sight: no box at all, or the 1×1 a clip rule leaves. */
async function expectHidden(page: Page, name: string, locator: ReturnType<Page['locator']>): Promise<void> {
    await expect(locator, `${name} is in the page for a screen reader`).toHaveCount(1);
    const box = await locator.boundingBox();
    expect(box === null || (box.width <= 1 && box.height <= 1), `${name} drawn at ${JSON.stringify(box)}`).toBe(true);
}

test('the upload control keeps its screen-reader text off the screen', async ({ page }, testInfo) => {
    const formId = await publishedPhotoForm(page, `M155 upload text (${testInfo.project.name})`);

    try {
        await page.goto(`/forms/${formId}/submissions/create`, { waitUntil: 'networkidle' });
        const question = page.getByRole('group', { name: /Photo of the site/ });
        await expect(question).toBeVisible();

        await expectHidden(page, 'the "(required)" beside the question', question.getByText('(required)', { exact: true }));

        await question.locator('input[type="file"]').setInputFiles({ name: 'site-photo.png', mimeType: 'image/png', buffer: PNG });
        await expect(question.getByText('site-photo.png', { exact: true })).toBeVisible();

        await expectHidden(page, 'the ✕ button\'s "Remove site-photo.png"', question.getByText('Remove site-photo.png', { exact: true }));

        // Uploaded or not, the control says which; either sentence is for a screen reader only.
        const announcement = question.locator('[aria-live="polite"]');
        await expect(announcement).toHaveText(/site-photo\.png (uploaded|failed to upload)\./, { timeout: 20_000 });
        await expectHidden(page, 'the announcement', announcement);
    } finally {
        await archive(page, formId);
    }
});
