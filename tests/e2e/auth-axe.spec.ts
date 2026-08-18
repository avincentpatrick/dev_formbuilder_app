import { expect, test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';
// The SHARED derivation, not a fourth hand-rolled `baseURL.replace('acme.', '')`. `hosts.ts` was created
// to collapse exactly these copies and `global-setup.ts` already imports it; this file and
// `responsive-axe.spec.ts` were the two that still hand-rolled it. Three copies of a string transform is
// how one of them comes to be subtly different.
import { centralOrigin, tenantOrigin } from './support/hosts';

/**
 * The AUTHENTICATION pages (Increment I8c) — the first and last screens of the product to carry an
 * accessibility gate, and until now the only composed surfaces with none.
 *
 * ⚠️ THEY WERE MISSED FOR A STRUCTURAL REASON, NOT AN OVERSIGHT. `playwright.config.ts` sets
 * `use.storageState` at the TOP LEVEL, so every project is authenticated and every existing scan runs as
 * the seeded demo Owner — which is exactly the state in which a sign-in page redirects away. So the
 * pages a locked-out user, a new invitee and anyone recovering an account all meet were the pages the
 * merge-blocking a11y gate could not see.
 *
 * The fix is the per-file idiom already proven twice in this suite (`public-runtime-axe.spec.ts:10` and
 * `responsive-axe.spec.ts`'s platform-landing case), NOT a config restructure: a fourth project would
 * multiply every existing spec by a viewport it does not need, and the top-level `storageState` is load-
 * bearing for the eight authenticated specs that depend on it.
 *
 * ── ⚠️ REGISTER IS SCANNED ON THE **CENTRAL** HOST, DELIBERATELY ──────────────────────────────────────
 * `playwright.config.ts`'s baseURL is the TENANT host (`acme.meridian.test`), and `RegistrationGate`
 * consults the tenant's own `registration.invite_only` on a subdomain — which **defaults to true**, the
 * fail-closed reading of "nobody has decided". So `/register` on the tenant host 404s for a workspace
 * that has not opted in, and a relative path here would fail for a reason that has nothing to do with
 * accessibility. The central host consults `registration.open_signup` instead, which defaults open. The
 * absolute-URL idiom is `responsive-axe.spec.ts`'s platform-landing case.
 *
 * ── Scope: THE FOUR DEFERRED PAGES ARE HERE NOW (J3b), AND TWO OF THEM NEEDED NO FIXTURE AT ALL ───────
 * This block used to say four pages "need a fixture with its own lifetime". Checking that against the
 * code rather than against the note found half of it wrong, which is the cheaper half of the work:
 *
 *   · Reset password — NO fixture. Fortify's `GET /reset-password/{token}` renders the form without
 *     consulting the token; validation happens on POST. The DOM is identical for any token string. The
 *     seeder does now carry a real `password_reset_tokens` row so the URL below is honest rather than a
 *     dead link, but the scan does not depend on it.
 *   · Two-factor challenge — **SCANNED SINCE J3c1, IN BOTH PANEL STATES.** Building this scan in J3b is
 *     what found that the page was unreachable by anyone: `POST /login` correctly answered
 *     `302 → /two-factor-challenge`, and that page then answered `302 → /login`, forever, so everyone who
 *     enrolled in 2FA was locked out. Fortify's `TwoFactorLoginRequest::hasChallengedUser()` resolved the
 *     pending user with a STATIC `$model::find(session('login.id'))` on the DEFAULT connection — and
 *     mid-login there is no `app.current_user_id`, so `users_users_visibility` hid the row. J3c1 routes
 *     that lookup through `RlsAwareUserProvider`, the pre-auth path the rest of the application already
 *     used. The seeded identity (`twofactor@meridian.test`), kept unused since J3b for exactly this, is
 *     what the scan below drives.
 *   · Verify email — the seeder's `pending@meridian.test`, the fixture's one unverified identity, which
 *     J3a created for exactly this. It needed only a KNOWN password; it previously carried a random one.
 *   · Invitation — the seeder's invite row, whose token was `Str::random(48)` and is now a constant. The
 *     stored column is its SHA-256, so a fixed plaintext leaks nothing a fixed seeded password does not.
 *
 * Two pages sit behind `auth` and are therefore scanned by `responsive-axe.spec.ts`, where a session
 * exists: Confirm password (since I8c) and, from J3b, `/two-factor/required` — which was scanned by
 * nothing at all despite embedding the widest content in the auth family, the shared `TwoFactorSetup`
 * panel with its QR code and recovery-code list.
 *
 * ⚠️ EVERY CASE BELOW THAT NEEDS ITS OWN IDENTITY OPENS ITS OWN CONTEXT. The file-level
 * `test.use({ storageState: … })` empties the session for the unauthenticated pages; a page behind `auth`
 * cannot simply be added to that list, because it would be scanned as the LOGIN page it redirects to and
 * would pass while covering nothing. That hazard is the reason this file's own header exists.
 */

test.use({ storageState: { cookies: [], origins: [] } });

const pages = [
    // The single most-visited unauthenticated page in the product.
    { name: 'Login', path: '/login' },
    { name: 'Forgot password', path: '/forgot-password' },
    { name: 'Register', path: `${centralOrigin}/register` },
    // J3b — see the header. The token is never validated on GET, so this scans the same DOM a real
    // recovery link produces; `E2eSeeder::RESET_TOKEN` is the matching row for anything that later posts.
    { name: 'Reset password', path: '/reset-password/e2e-password-reset-token?email=demo%40meridian.test' },
    // The invite token below is `E2eSeeder::INVITE_TOKEN`; the column stores its SHA-256.
    { name: 'Invitation', path: '/invitations/e2e-invitation-token-do-not-reuse-in-production' },
] as const;

const themes = ['light', 'dark'] as const;

for (const p of pages) {
    for (const theme of themes) {
        test(`${p.name} (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
            await page.goto(p.path, { waitUntil: 'networkidle' });
            await forceTheme(page, theme);
            await assertClean(page, p.name);
        });
    }
}

/*
| The two pages that need a session of their own (J3b, J3c1).
|
| Each signs in inside the file's empty storage state rather than borrowing the suite's saved one, for the
| reason the header gives: the saved session belongs to a VERIFIED owner with no second factor, and these
| two pages exist only for someone who is neither.
|
| ⚠️ NEVER `networkidle` AFTER THE SUBMIT. The login form is an Inertia XHR, so the page performs no
| document navigation and that wait resolves instantly against the already-idle previous load —
| `global-setup.ts:40-44` records the four CI cycles that cost. Wait on the URL, which is both the sync
| point and the assertion that the right thing happened.
*/
for (const theme of themes) {
    test(`Verify email (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto(`${tenantOrigin}/login`, { waitUntil: 'networkidle' });
        await page.getByLabel('Email', { exact: true }).fill('pending@meridian.test');
        await page.getByLabel('Password', { exact: true }).fill('meridian-e2e-2026');
        await page.getByRole('button', { name: 'Sign in' }).click();

        // The `verified` gate J3a mounted is what puts them here: this identity is the fixture's only
        // unverified one, so any authenticated destination bounces to the notice.
        await page.waitForURL(/\/email\/verify$/, { timeout: 30_000 });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        await forceTheme(page, theme);
        await assertClean(page, 'Verify email');
    });
}

/*
| The two-factor challenge (J3c1) — the page this suite could not scan until it could be reached.
|
| Building this scan in J3b is what FOUND the lockout: `POST /login` answered `302 → /two-factor-challenge`
| and that page answered `302 → /login`, forever, for everyone. The seeded `twofactor@meridian.test`
| identity was created then and deliberately left unused, because it is exactly what the fix would need.
| This is that use. See `docs/feature-backlog.md` for the closed entry.
|
| ⚠️ BOTH PANEL STATES ARE SCANNED, AND THE SECOND HAS NEVER BEEN SCANNED BY ANYTHING. The page is a
| `v-if`/`v-else` pair — an authentication-code field or a recovery-code field, with different labels and
| different help text — and only one of them is in the DOM at a time. A single scan would cover half the
| page and report itself as covering the page.
|
| ⚠️ This identity is a member of NO workspace, which is what makes it safe to drive here: it can reach the
| challenge (Fortify diverts on credentials alone, before any membership is consulted) and it is never
| submitted, so the scan cannot accidentally complete a sign-in and land somewhere else.
*/
for (const theme of themes) {
    test(`Two-factor challenge (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto(`${tenantOrigin}/login`, { waitUntil: 'networkidle' });
        await page.getByLabel('Email', { exact: true }).fill('twofactor@meridian.test');
        await page.getByLabel('Password', { exact: true }).fill('meridian-e2e-2026');
        await page.getByRole('button', { name: 'Sign in' }).click();

        await page.waitForURL(/\/two-factor-challenge$/, { timeout: 30_000 });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        await forceTheme(page, theme);
        await assertClean(page, 'Two-factor challenge');

        // The other half of the page. No navigation happens, so the theme set above survives the click.
        await page.getByRole('button', { name: 'Use a recovery code' }).click();
        await expect(page.getByLabel('Recovery code', { exact: true })).toBeVisible();
        await assertClean(page, 'Two-factor challenge (recovery)');
    });
}
