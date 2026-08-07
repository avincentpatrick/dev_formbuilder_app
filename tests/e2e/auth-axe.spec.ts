import { test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';

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
 * ── Scope, and what is deliberately not here ──────────────────────────────────────────────────────────
 * The three genuinely stateless, genuinely unauthenticated pages are covered. Four others need a fixture
 * with its own lifetime and are filed in `docs/feature-backlog.md` rather than half-built: Reset password
 * (a live signed token), the two-factor challenge (a `login.id` session mid-login), Verify email (an
 * unverified authenticated user) and the invitation page (an unconsumed invite). A fifth, Confirm
 * password, is not here because it sits behind `auth` — it is scanned by `responsive-axe.spec.ts`, where
 * a session exists, which is also where it belongs.
 */

test.use({ storageState: { cookies: [], origins: [] } });

// The central host, derived the same way responsive-axe derives it for the platform landing page.
const centralOrigin = (process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000').replace('acme.', '');

const pages = [
    // The single most-visited unauthenticated page in the product.
    { name: 'Login', path: '/login' },
    { name: 'Forgot password', path: '/forgot-password' },
    { name: 'Register', path: `${centralOrigin}/register` },
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
