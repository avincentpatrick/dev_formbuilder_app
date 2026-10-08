import { expect, type Page } from '@playwright/test';

/*
 * A spec that edits a form makes its OWN, and archives it again (M139's reason: every spec shares one seeded database
 * across three viewport projects). Moved here from `builder-preview-authoring.spec.ts` by M150, when a second spec
 * needed the same three helpers.
 */

export async function createForm(page: Page, title: string): Promise<string> {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'New form' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Title').fill(title);
    await dialog.getByRole('button', { name: 'Create form' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/builder$/, { timeout: 30_000 });

    return /\/forms\/([0-9a-f-]{36})\/builder$/.exec(page.url())![1];
}

export async function archive(page: Page, formId: string): Promise<void> {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post(`/forms/${formId}/archive`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } });
    expect(response.status(), 'the form this spec made is archived again').toBeLessThan(400);
}

export async function saved(page: Page): Promise<void> {
    await expect(page.getByText('All changes saved').first()).toBeVisible({ timeout: 20_000 });
}

/** The next POST to this form's reorder door — start waiting BEFORE the key or the release that sends it. */
export function reorderSent(page: Page, formId: string): Promise<unknown> {
    return page.waitForResponse(
        (response) => response.request().method() === 'POST' && response.url().endsWith(`/forms/${formId}/reorder`),
        { timeout: 20_000 },
    );
}
