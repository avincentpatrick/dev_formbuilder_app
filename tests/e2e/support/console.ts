import { expect, type Page } from '@playwright/test';
import { centralOrigin } from './hosts';

/**
 * Open a central-domain admin-console route, clearing the step-up challenge if it fires (Increment I10e).
 *
 * ── WHY THE STEP-UP IS HANDLED PER NAVIGATION AND NOT BAKED INTO `storageState` ────────────────────────
 * `RequireRecentPassword` (I8a) narrows the console to `config('auth.step_up_timeout')` — 900 seconds. The
 * e2e job runs ~13 minutes across three viewport projects, so a step-up confirmed once in `globalSetup`
 * can expire mid-run and take a scan down with it. That is an intermittent red on a merge-blocking gate,
 * i.e. exactly the class of failure I10b was graduated to remove; re-confirming on demand is O(1) extra
 * POSTs and cannot age out. Raising AUTH_STEP_UP_TIMEOUT in CI to dodge it would be testing a
 * configuration nobody runs.
 *
 * ── ⚠️ THE FINAL URL ASSERTION IS NOT DECORATION ───────────────────────────────────────────────────────
 * Without it this helper would happily return having landed on `/login`, `/admin/two-factor` or the
 * confirm-password form, and the caller's `assertClean` would scan THAT page and pass — a green
 * accessibility gate over a page the suite never intended to visit. `auth-axe.spec.ts` records the same
 * hazard for its own no-storageState idiom. If this assertion fails, the fixture is wrong; do not relax it.
 */
export async function openConsole(page: Page, path: string): Promise<void> {
    const target = `${centralOrigin}${path}`;

    await page.goto(target, { waitUntil: 'networkidle' });

    // The step-up interstitial, if this navigation tripped it. Idempotent: on a warm window nothing matches
    // and we fall straight through.
    if (page.url().includes('/user/confirm-password')) {
        await page.getByLabel('Password', { exact: true }).fill('meridian-console-2026');
        await page.getByRole('button', { name: /confirm/i }).click();
        await page.waitForURL(target, { timeout: 30_000 });
    }

    expect(page.url(), `expected to be on ${path}, not ${page.url()}`).toContain(path);
}
