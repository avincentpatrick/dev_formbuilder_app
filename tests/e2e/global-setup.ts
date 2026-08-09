import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';

// Authenticate once as the E2eSeeder's demo Owner and persist the session for every spec/project.
// The login form (design-system-styled) exposes fields by their <label>, not a name attribute.
async function globalSetup(_config: FullConfig): Promise<void> {
    const baseURL = process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000';
    mkdirSync('tests/e2e/.auth', { recursive: true });

    const browser = await chromium.launch();
    const page = await browser.newPage({ ignoreHTTPSErrors: true });

    await page.goto(`${baseURL}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email', { exact: true }).fill('demo@meridian.test');
    await page.getByLabel('Password', { exact: true }).fill('meridian-e2e-2026');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/dashboard', { timeout: 30_000 });

    await page.context().storageState({ path: 'tests/e2e/.auth/state.json' });

    // Increment I10e — a SECOND session for the central-domain console.
    //
    // A separate context, not a second login in the first one, because `SESSION_DOMAIN` is null in both
    // `.env` and `.env.example`: the session cookie is HOST-ONLY, so a login on `acme.meridian.test` is
    // simply not sent to `meridian.test`. `routes/tenant.php`'s own header records that correction.
    //
    // ⚠️ THE CONSOLE ROUTE IS VISITED FIRST, AS A GUEST, AND THAT IS THE WHOLE FIX. `Authenticate` answers
    // with `redirect()->guest(route('login'))`, and `Redirector::guest()` STORES `url.intended` — so
    // Fortify's `redirect()->intended()` brings the login back HERE. Without it the target is
    // `fortify.home` = `/dashboard`, a TENANT route: on the central host `InitializeTenancyBySubdomain`
    // cannot identify a tenant, and bootstrap/app.php answers `NotASubdomainException` with a redirect to
    // the ABSOLUTE `config('app.url')` — which in CI is the tenant origin. An Inertia XHR cannot follow
    // that (an external redirect needs `Inertia::location()`'s 409, not a 302), so the visit dies with the
    // page still sitting on /login and no error rendered.
    //
    // ⚠️ AND NEVER `waitForLoadState('networkidle')` AFTER THIS SUBMIT. The login form is an Inertia XHR
    // (`resources/js/pages/auth/Login.vue` → `form.post`), so the page performs NO document navigation and
    // that call resolves INSTANTLY against the already-idle previous load. The previous version then raced
    // ahead to `/admin/settings` before the POST had landed and read a GUEST session — which is what four
    // CI cycles were spent chasing, and it was never a credentials, CSRF, TOTP or session-driver fault.
    // `tests/Feature/Auth/CentralHostLoginTest.php` pins the server side of that separately.
    //
    // Waiting on the URL is therefore both the sync point AND the session assertion: every candidate below
    // sits behind `auth`, so arriving at one proves a real authenticated session. `/user/confirm-password`
    // is the expected landing (step-up, I8a); `/admin/settings` would mean a confirmation was already live.
    //
    // The step-up challenge is deliberately NOT confirmed here — it expires in 900s and this job runs far
    // longer than that. `tests/e2e/support/console.ts` clears it per navigation instead.
    const centralOrigin = baseURL.replace('acme.', '');
    const consolePage = await browser.newPage({ ignoreHTTPSErrors: true });

    await consolePage.goto(`${centralOrigin}/admin/settings`, { waitUntil: 'networkidle' });
    await consolePage.getByLabel('Email', { exact: true }).fill('console@meridian.test');
    await consolePage.getByLabel('Password', { exact: true }).fill('meridian-console-2026');
    await consolePage.getByRole('button', { name: 'Sign in' }).click();

    // No TOTP hop on the way: the seeded operator has `two_factor_confirmed_at` set with a NULL secret, so
    // Fortify does not consider two-factor ENABLED and issues no challenge, while `EnsureSuperAdminMfa` —
    // which reads only the timestamp — lets the console through. E2eSeeder::seedSuperAdmin() explains it.
    await consolePage.waitForURL(/\/(user\/confirm-password|admin\/settings)$/, { timeout: 30_000 });

    await consolePage.context().storageState({ path: 'tests/e2e/.auth/admin.json' });

    await browser.close();
}

export default globalSetup;
