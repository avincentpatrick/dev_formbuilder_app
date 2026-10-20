import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';

/**
 * M181 (`R-c7247dbf`, `D125` C, `D126` A, `D157` A) — What's new, in a real browser. The user (2026-10-14): *"i want
 * the whats new to be seen right after logging in"*, and *"lets put the current version in the app, footer and login
 * so users are aware"*. This walks it as a person would: the version on the sign-in card, the prompt on the page they
 * land on, Got it, the prompt gone after a reload, and the footer's way back to every release.
 *
 * ⚠️ AS THE ONE UNSEEN MEMBER, SIGNED IN FRESH. Every other seeded identity is stamped as having seen the newest
 * release (`E2eSeeder::seedWhatsNew()`) — the prompt makes the page behind it inert, and demo@'s stored session opens
 * every other spec. `whatsnew@` is reset to unseen on every seed.
 *
 * ⚠️ DESKTOP ONLY. Closing the prompt moves `whatsnew@`'s seen version, and three viewport projects share one seeded
 * database, so it can be closed once per seed.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const VERSION = readFileSync(join(process.cwd(), 'VERSION'), 'utf8').trim();
const NEWEST = (JSON.parse(readFileSync(join(process.cwd(), 'database/data/releases.json'), 'utf8')) as { title: string }[])[0];

test.describe("What's new — the prompt after sign-in", () => {
    test.beforeEach(async ({}, info) => {
        test.skip(info.project.name !== 'desktop', 'one shared database; closing the prompt marks it seen, so it runs once');
    });

    test('shows the version at sign-in, opens on the page they land on, and stays closed once closed', async ({ page }) => {
        await page.goto('/login', { waitUntil: 'networkidle' });
        await expect(page.locator('.auth__card .auth__version')).toHaveText(`Meridian v${VERSION}`);

        await page.getByLabel('Email', { exact: true }).fill('whatsnew@meridian.test');
        await page.getByLabel('Password', { exact: true }).fill('meridian-e2e-2026');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL('**/dashboard', { timeout: 30_000 });

        const prompt = page.getByRole('dialog', { name: `What's new in Meridian ${VERSION}` });
        await expect(prompt).toBeVisible({ timeout: 15_000 });
        await expect(prompt.getByRole('heading', { level: 3, name: NEWEST.title })).toBeVisible();

        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/whats-new/seen') && r.request().method() === 'POST', { timeout: 30_000 }),
            prompt.getByRole('button', { name: 'Got it' }).click(),
        ]);
        await expect(prompt).toBeHidden();

        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(0);

        await expect(page.locator('.sidebar__foot')).toContainText(`v${VERSION}`);
        const footer = page.locator('footer.app-shell__footer');
        await expect(footer).toContainText(`Meridian v${VERSION}`);
        await footer.getByRole('link', { name: "What's new" }).click();
        await page.waitForURL('**/whats-new');

        await expect(page.getByRole('heading', { level: 1, name: "What's new" })).toBeVisible();
        await expect(page.getByRole('heading', { level: 2, name: NEWEST.title })).toBeVisible();
        await expect(page.getByText(`Version ${VERSION}`)).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(0);
    });
});
