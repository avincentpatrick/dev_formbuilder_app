import { test, expect, type Locator, type Page } from '@playwright/test';
import { archive, createForm } from './support/builder';
import { assertNoHorizontalOverflow } from './support/axe';

/*
 * M187 (`R-af2d278b`) — a Signature anyone can sign, in a real browser. Until M187 both channels said "Not available
 * for manual entry yet" and the question could not be answered at all.
 *
 * ⛔ WHAT ONLY A BROWSER SETTLES: happy-dom draws nothing and fires no real pointer stream, so the unit tests stub the
 * canvas. Here a mouse really draws on the pad, the pad really exports a PNG, the guest endpoint really stages it, the
 * response really submits, and the response page really serves the picture back through `/attachments/{id}` once the
 * worker has checked it (Phase 1 marks it skipped).
 *
 * The form is made through the routes the builder itself calls (the `calculations-and-defaults` precedent): one required
 * Signature. It runs at a phone's width and a desktop's, and archives its form.
 */

async function xsrf(page: Page): Promise<Record<string, string>> {
    const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

async function draftHeader(page: Page, formId: string): Promise<Record<string, string>> {
    await page.goto(`/forms/${formId}/builder`, { waitUntil: 'load' });
    const json = await page.locator('script[data-page]').first().textContent();
    const draftId = (JSON.parse(json ?? '{}') as { props?: { draft?: { id?: string } } }).props?.draft?.id ?? '';
    expect(draftId, 'the builder page names its draft').not.toBe('');

    return { 'X-Builder-Draft': draftId };
}

/** A published, shared form whose one question is a required Signature; returns its id. */
async function signatureForm(page: Page, slug: string): Promise<string> {
    const formId = await createForm(page, `Signature ${slug}`);
    const draft = await draftHeader(page, formId);

    const created = await page.request.post(`/forms/${formId}/fields`, { headers: { ...(await xsrf(page)), ...draft }, data: { field_type: 'signature', section_id: null } });
    expect(created.status(), 'a signature question is added').toBeLessThan(400);
    const field = await created.json();
    const saved = await page.request.patch(`/forms/${formId}/fields/${field.id}`, {
        headers: { ...(await xsrf(page)), ...draft },
        data: { key: 'signed', label: 'Signature of the respondent', is_required: 'required', config: field.config ?? {}, validations: [], version: field.version },
    });
    expect(saved.status(), `the signature question is saved: ${await saved.text()}`).toBeLessThan(400);

    const published = await page.request.post(`/forms/${formId}/publish`, { headers: await xsrf(page), data: {} });
    expect(published.status(), `the form is published: ${await published.text()}`).toBeLessThan(400);
    const shared = await page.request.patch(`/forms/${formId}/share`, {
        headers: await xsrf(page),
        data: { allow_guest_submissions: true, bot_challenge: 'off', guest_rate_limit_per_minute: null, public_slug: slug },
        // It answers with a redirect back to the builder, and a followed redirect would re-send PATCH to a GET page.
        maxRedirects: 0,
    });
    expect(shared.status(), `the form is shared: ${await shared.text()}`).toBeLessThan(400);

    return formId;
}

/** Sign the pad: two strokes, the way a name is written, with the pen lifted between them. */
async function sign(page: Page, pad: Locator): Promise<void> {
    await pad.scrollIntoViewIfNeeded();
    const box = await pad.boundingBox();
    expect(box, 'the pad is drawn').not.toBeNull();
    const { x, y, width, height } = box!;

    await page.mouse.move(x + width * 0.1, y + height * 0.7);
    await page.mouse.down();
    for (let i = 1; i <= 12; i += 1) {
        await page.mouse.move(x + width * (0.1 + i * 0.03), y + height * (0.7 - (i % 3) * 0.12));
    }
    await page.mouse.up();

    await page.mouse.move(x + width * 0.55, y + height * 0.4);
    await page.mouse.down();
    await page.mouse.move(x + width * 0.75, y + height * 0.65, { steps: 8 });
    await page.mouse.up();
}

test('Signature — a guest signs on the pad, and the response shows the picture', async ({ page }, info) => {
    test.skip(info.project.name === 'tablet', 'measured at a phone and a desktop');
    test.setTimeout(180_000);
    const slug = `m187-sign-${info.project.name}-${Date.now()}`;
    const formId = await signatureForm(page, slug);

    try {
        // Manual entry has the pad too, and saves through the member upload endpoint.
        await page.goto(`/forms/${formId}/submissions/create`, { waitUntil: 'networkidle' });
        const staffQuestion = page.getByRole('group', { name: /Signature of the respondent/ });
        await expect(staffQuestion.getByText('Not available for manual entry yet')).toHaveCount(0);
        await sign(page, staffQuestion.getByRole('img', { name: /Signature pad/ }));
        await expect(staffQuestion.getByText('Saved', { exact: true })).toBeVisible({ timeout: 20_000 });

        // The guest form: draw, wait for the save, submit.
        await page.goto(`/f/${slug}`, { waitUntil: 'networkidle' });
        const question = page.getByRole('group', { name: /Signature of the respondent/ });
        const pad = question.getByRole('img', { name: /Signature pad/ });
        await expect(pad).toBeVisible();
        await expect(question.getByText('Sign here')).toBeVisible();
        await assertNoHorizontalOverflow(page, `the guest form with a signature pad (${info.project.name})`);

        await sign(page, pad);
        await expect(question.getByText('Saved', { exact: true })).toBeVisible({ timeout: 20_000 });
        await expect(question.locator('[aria-live="polite"]')).toHaveText('Signature saved.');

        await page.getByRole('button', { name: 'Submit' }).click();
        await expect(page.getByRole('heading', { name: 'Thanks — your response has been recorded.' })).toBeVisible({ timeout: 15_000 });

        // The response page draws the signature once the worker has checked the file.
        await page.goto(`/forms/${formId}/submissions`, { waitUntil: 'networkidle' });
        const hrefs = await page.locator('a[href*="/submissions/"]').evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
        const href = hrefs.find((h) => /\/submissions\/[0-9a-f]{8}-[0-9a-f-]{27}$/.test(h)) ?? null;
        expect(href, `the response is listed among ${hrefs.join(', ')}`).not.toBeNull();

        await expect(async () => {
            await page.goto(href!, { waitUntil: 'networkidle' });
            const picture = page.getByRole('img', { name: 'Signature' });
            await expect(picture).toBeVisible({ timeout: 2_000 });
            const drawn = await picture.evaluate((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth);
            expect(drawn, 'the picture loaded from the attachment route').toBe(900);
        }).toPass({ timeout: 60_000, intervals: [2_000] });
    } finally {
        await archive(page, formId);
    }
});
