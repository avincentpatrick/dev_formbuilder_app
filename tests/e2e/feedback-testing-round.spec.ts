import { expect, test, type Page } from '@playwright/test';

/**
 * M179 (`R-129c44c3`, `D150`, `D151`) — a tester checks a fix, in a real browser. The first spec in the repository
 * that walks a status change end to end: the seeded relayed reports (E2eSeeder::RELAYED_FIXTURES), the page's
 * tab, the server's actions, the post, the redirect back, and the page as it then reads.
 *
 * ⚠️ AS A MEMBER, NOT THE OWNER. The default storage state is the demo Owner, who may act on every report — so a
 * walk signed in as them would prove nothing about what a member sees. This spec starts with no session and signs
 * in as the seeded reviewer (a member without `feedback.view`), who may act on a relayed report only because
 * every member may (`FeedbackReportPolicy`).
 *
 * ⚠️ DESKTOP ONLY, ON ITS OWN ROWS. Three viewport projects share one seeded database, and this spec MOVES rows:
 * it runs once, on `E2E-WALK-1` and `E2E-WALK-2`, which nothing else reads. A re-seed puts them back.
 *
 * ⚠️ EACH MOVE WAITS FOR ITS OWN POST TO ANSWER, then reads the page. Measured on the local stack: the reopen's
 * round trip (a multipart POST, the redirect back, the page re-rendered with every row's thread) outlasted the
 * default five-second expect while the server had already committed it — the button still disabled in the
 * snapshot. Waiting on the response is the honest wait; the longer window covers the re-render after it.
 */
test.use({ storageState: { cookies: [], origins: [] } });

async function signInAsMember(page: Page): Promise<void> {
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.getByLabel('Email', { exact: true }).fill('reviewer@meridian.test');
    await page.getByLabel('Password', { exact: true }).fill('meridian-e2e-2026');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/dashboard', { timeout: 30_000 });
}

test.describe('the testing round — a member checks a fix', () => {
    test.beforeEach(async ({ page }, info) => {
        test.skip(info.project.name !== 'desktop', 'one shared database; the walk moves its seeded rows, so it runs once');
        await signInAsMember(page);
    });

    test('opens on the testing round from its link, and It works turns a Resolved comment into Confirmed working', async ({ page }) => {
        await page.goto('/feedback/mine?view=testing');

        await expect(page.getByRole('tab', { name: /Testing-round comments \(\d+ open\)/ })).toHaveAttribute('aria-selected', 'true');

        const card = page.locator('[data-feedback-report="E2E-WALK-1"]');
        await expect(card).toBeVisible();
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/confirm') && r.request().method() === 'POST', { timeout: 30_000 }),
            card.getByRole('button', { name: 'It works' }).click(),
        ]);

        await expect(card.locator('[data-feedback-confirmed]')).toContainText('Confirmed working by Rita Reviewer', { timeout: 20_000 });
        await expect(card.getByRole('button', { name: 'It works' })).toHaveCount(0);
    });

    test('Still a problem asks for a reason first, then sends the report back to Ongoing with it in the thread', async ({ page }) => {
        await page.goto('/feedback/mine?view=testing');

        const card = page.locator('[data-feedback-report="E2E-WALK-2"]');
        await card.getByRole('button', { name: 'Still a problem' }).click();
        await card.getByRole('button', { name: 'Send', exact: true }).click();
        await expect(card).toContainText('Say what is still a problem');

        await card.getByLabel('What is still a problem?').fill('The last line is still cut off on the tablet.');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/reopen') && r.request().method() === 'POST', { timeout: 30_000 }),
            card.getByRole('button', { name: 'Send', exact: true }).click(),
        ]);

        await expect(card).toContainText('Ongoing', { timeout: 20_000 });
        await expect(card.locator('[data-feedback-thread]')).toContainText('The last line is still cut off on the tablet.');
        await expect(card.locator('[data-feedback-thread]')).toContainText('said it is still a problem');
    });
});
