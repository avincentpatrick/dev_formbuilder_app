import { test, expect, type Page } from '@playwright/test';
import { archive, createForm } from './support/builder';

/*
 * M180 (R4: `R-f77aa45d`, `R-da02fe03`, `R-20784770`, `R-39dbce5b`) — calculations and defaults, in a real browser. The
 * user (2026-10-16): "create a calculated field using the form's own questions by clicking or selecting … put presets".
 *
 * ⛔ THE CASE ONLY A BROWSER CAN SETTLE: a default the respondent cleared must stay cleared across a reload. The guest's
 * own draft is restored from IndexedDB AFTER the form opened and filled its defaults (`RuntimeSession`'s `restoreDraft`),
 * so a merge there would bring the default back — the unit tests drive the store, not that wiring. It is the NUMBER that
 * proves it: a cleared number box is saved as null, which a merging restore skips; a cleared text box is saved as '',
 * which a merge writes back anyway (measured: a merging restore kept this spec green until the number was added).
 *
 * The form is made through the routes the builder itself calls (the `media-input-hidden-text` precedent): a date of birth,
 * an Age calculation shown as a Duration in years (Always), a Town with the default Pasig, a Household size with the
 * default 4, and a question shown only
 * when the age is 18 or more — a condition reading a calculation. Desktop only; it archives its form. Labels match by
 * substring: an optional question is named "Town (optional)".
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

/** One question added and saved through the builder's routes. */
async function question(page: Page, formId: string, draft: Record<string, string>, type: string, data: Record<string, unknown>): Promise<void> {
    const created = await page.request.post(`/forms/${formId}/fields`, { headers: { ...(await xsrf(page)), ...draft }, data: { field_type: type, section_id: null } });
    expect(created.status(), `a ${type} question is added`).toBeLessThan(400);
    const field = await created.json();

    const saved = await page.request.patch(`/forms/${formId}/fields/${field.id}`, {
        headers: { ...(await xsrf(page)), ...draft },
        data: { key: field.key, is_required: 'optional', config: field.config ?? {}, validations: [], version: field.version, ...data },
    });
    expect(saved.status(), `the ${type} question is saved: ${await saved.text()}`).toBeLessThan(400);
}

/** Thirty years and ten days ago, as a date input takes it — 30 on any clock, in any zone. */
function thirtyYearsAgo(): string {
    const at = new Date();
    at.setUTCFullYear(at.getUTCFullYear() - 30);
    at.setUTCDate(at.getUTCDate() - 10);

    return at.toISOString().slice(0, 10);
}

test('Calculations and defaults — an age by its Type, a condition reading it, and a default kept cleared across a reload', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'the runtime is the same at every width');
    test.setTimeout(240_000);
    const slug = `m180-calc-${Date.now()}`;
    const formId = await createForm(page, `Calculations and defaults ${Date.now()}`);

    try {
        const draft = await draftHeader(page, formId);
        await question(page, formId, draft, 'date', { key: 'dob', label: 'Date of birth' });
        await question(page, formId, draft, 'calculated', {
            key: 'age', label: 'Age',
            config: { calculated_formula: 'age(${dob})', calculated_type: 'duration', duration_unit: 'years', visibility: 'shown' },
        });
        await question(page, formId, draft, 'short_text', { key: 'town', label: 'Town', default_value: 'Pasig', default_value_is_expression: false });
        await question(page, formId, draft, 'integer', { key: 'household', label: 'Household size', default_value: '4', default_value_is_expression: false });
        await question(page, formId, draft, 'short_text', { key: 'note', label: 'Anything else', relevant_expression: '${age} >= 18' });

        const published = await page.request.post(`/forms/${formId}/publish`, { headers: await xsrf(page), data: {} });
        expect(published.status(), `the form is published: ${await published.text()}`).toBeLessThan(400);
        const shared = await page.request.patch(`/forms/${formId}/share`, {
            headers: await xsrf(page),
            data: { allow_guest_submissions: true, bot_challenge: 'off', guest_rate_limit_per_minute: null, public_slug: slug },
            // It answers with a redirect back to the builder, and a followed redirect would re-send PATCH to a GET page.
            maxRedirects: 0,
        });
        expect(shared.status(), `the form is shared: ${await shared.text()}`).toBeLessThan(400);

        // Staff keying a NEW response start from the default too.
        await page.goto(`/forms/${formId}/submissions/create`, { waitUntil: 'networkidle' });
        await expect(page.getByLabel('Town')).toHaveValue('Pasig');

        // The guest form: the default filled in, the age worked out and shown in years, the condition on it applied.
        await page.goto(`/f/${slug}`, { waitUntil: 'networkidle' });
        const town = page.getByLabel('Town');
        await expect(town).toHaveValue('Pasig');
        const household = page.getByLabel('Household size');
        await expect(household).toHaveValue('4');
        await expect(page.getByLabel('Anything else')).toHaveCount(0);

        await page.getByLabel('Date of birth').fill(thirtyYearsAgo());
        await expect(page.locator('[data-computed] output')).toHaveText('30 years');
        await expect(page.getByLabel('Anything else')).toBeVisible();

        // Cleared, saved in this browser, reloaded: it stays cleared — the restored draft wins over the default.
        await town.fill('');
        await household.fill('');
        await page.waitForTimeout(2_500); // the browser's own draft is written after a short pause
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByLabel('Date of birth')).toHaveValue(thirtyYearsAgo());
        await expect(page.getByLabel('Town')).toHaveValue('');
        await expect(page.getByLabel('Household size')).toHaveValue('');
    } finally {
        await archive(page, formId);
    }
});
