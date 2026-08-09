import { expect, test } from '@playwright/test';
import { openConsole } from './support/console';
import { tenantOrigin } from './support/hosts';

/*
 * The cross-host impersonation hop (Increment I11b, RBAC §9 resolved decision 1).
 *
 * ── THIS IS THE ONLY PLACE THE FEATURE CAN ACTUALLY BE PROVEN ─────────────────────────────────────────
 * Every rule — eligibility, single use, the TTL, the ledger rows, the 30-minute cap — is covered by Pest
 * against the service. What no PHP test can reach is the thing this increment is actually shaped by:
 * `SESSION_DOMAIN` is null, so the session cookie is HOST-ONLY, and the whole design exists because an
 * operator authenticated on `meridian.test` has no session on `acme.meridian.test`. A Pest request cannot
 * cross an origin. A browser can, and that is the entire subject here.
 *
 * ── TWO STORAGE STATES, FOR THE SAME REASON I10e NEEDED THEM ──────────────────────────────────────────
 * `playwright.config.ts` sets `use.storageState` at the top level, authenticating every project as the
 * TENANT owner — a cookie that is never sent to the central host. The console operator is a different
 * account on a different origin, so this file overrides it with `admin.json`, the second state I10e
 * introduced. The run then STARTS with no tenant session at all, which is precisely the condition the
 * handoff has to establish one from.
 *
 * ── ⚠️ THE 409 IS WHAT IS BEING TESTED, THOUGH NOTHING ASSERTS THE STATUS CODE ────────────────────────
 * `Inertia::location()` answers 409 + `X-Inertia-Location`; a plain 302 to another origin is silently
 * un-followable by an Inertia XHR. From the browser's side that difference shows up as exactly one thing:
 * whether the URL changed. `expect(page).toHaveURL(/dashboard/)` IS the assertion — it is what stranded
 * I10e for four CI cycles, seen from the other end.
 */

test.use({ storageState: 'tests/e2e/.auth/admin.json' });

const TENANT_ID_PATH = '/admin/tenants';

test('an operator signs in to a workspace, is warned, and can leave', async ({ page }) => {
    // 1 — reach the workspace's console page. `openConsole` clears the step-up interstitial if the
    //     15-minute window has aged out mid-run.
    await openConsole(page, TENANT_ID_PATH);

    await page.getByRole('link', { name: /acme|Acme Research/i }).first().click();
    await expect(page).toHaveURL(/\/admin\/tenants\/[0-9a-f-]{36}$/);

    // The card states the consequences before anything happens. Asserted because it is the only place an
    // operator is told the access is visible to the workspace.
    await expect(page.getByText(/support access/i).first()).toBeVisible();

    // 2 — pick a member and confirm. The confirmation is deliberate: the next click leaves this origin.
    await page.getByRole('button', { name: 'Sign in as', exact: true }).first().click();
    await page.getByRole('button', { name: /^Sign in as .+/ }).click();

    // 3 — THE HOP. A different host, a session that did not exist a moment ago, and a landing on the
    //     tenant dashboard rather than its login page.
    await page.waitForURL(new RegExp(`^${escapeForRegExp(tenantOrigin)}/dashboard`), { timeout: 30_000 });

    // 4 — the banner. It renders in the shell above the nav, so it is present on the dashboard and would be
    //     present on every other page too; a support session must never be silent.
    const banner = page.getByTestId('impersonation-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText(/signed in as/i);
    await expect(banner).toContainText(/audit log/i);

    // 5 — the way out, back across the origin boundary to the console. `Inertia::location()` again, in the
    //     opposite direction, and again the URL is the only thing that proves it worked.
    await banner.getByRole('button', { name: /exit support session/i }).click();
    await page.waitForURL(/\/admin\/tenants\/[0-9a-f-]{36}$/, { timeout: 30_000 });

    // And the tenant session is genuinely gone rather than merely navigated away from: the workspace now
    // answers with its login page. Without this, an "exit" that only redirected would pass.
    await page.goto(`${tenantOrigin}/dashboard`, { waitUntil: 'networkidle' });
    await expect(page).toHaveURL(/\/login/);
});

/** Escape an origin for embedding in a RegExp — `.` and `:` are literal here, not pattern syntax. */
function escapeForRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
