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
    // ⚠️ DO NOT WAIT ON THE POST-LOGIN REDIRECT HERE. `fortify.home` is `/dashboard`, which is a TENANT
    // route — on the central host `InitializeTenancyBySubdomain` cannot resolve a tenant from `meridian.test`
    // and the landing page is meaningless. The first version of this setup waited for the URL to leave
    // `/login` and timed out for that reason, not because the credentials were wrong (the diagnostic showed
    // a CLEAN login form, no validation error). All this step actually needs is the SESSION COOKIE, so it
    // asserts the session by fetching a console route instead of by watching where Fortify redirects.
    //
    // The step-up challenge is deliberately NOT confirmed here — it expires in 900s and this job runs far
    // longer than that. `tests/e2e/support/console.ts` clears it per navigation instead.
    const centralOrigin = baseURL.replace('acme.', '');
    const consolePage = await browser.newPage({ ignoreHTTPSErrors: true });

    await consolePage.goto(`${centralOrigin}/login`, { waitUntil: 'networkidle' });
    await consolePage.getByLabel('Email', { exact: true }).fill('console@meridian.test');
    await consolePage.getByLabel('Password', { exact: true }).fill('meridian-console-2026');
    // Record what the POST actually answered. A bare wait cannot distinguish "never submitted" from
    // "submitted and rejected" from "submitted, accepted, cookie dropped", and those want different fixes.
    const seen: string[] = [];
    consolePage.on('response', (r) => {
        if (r.request().method() === 'POST' || r.url().includes('/login') || r.url().includes('/admin')) {
            seen.push(`${r.request().method()} ${r.status()} ${r.url()}`);
        }
    });

    await consolePage.getByRole('button', { name: 'Sign in' }).click();
    await consolePage.waitForLoadState('networkidle');

    // No TOTP hop: the seeded operator has `two_factor_confirmed_at` set with a NULL secret, so Fortify does
    // not consider two-factor ENABLED and issues no challenge, while `EnsureSuperAdminMfa` — which reads only
    // the timestamp — lets the console through. E2eSeeder::seedSuperAdmin() explains the trade.
    await consolePage.goto(`${centralOrigin}/admin/settings`, { waitUntil: 'networkidle' });

    if (new URL(consolePage.url()).pathname.startsWith('/login')) {
        const shown = (await consolePage.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 400);
        const cookies = (await consolePage.context().cookies()).map((c) => `${c.name}@${c.domain}`).join(', ');
        throw new Error(
            `console sign-in did not establish a session (${consolePage.url()}).
` +
                `Requests: ${seen.join(' | ')}
Cookies: ${cookies}
Page said: ${shown}`,
        );
    }

    await consolePage.context().storageState({ path: 'tests/e2e/.auth/admin.json' });

    await browser.close();
}

export default globalSetup;
