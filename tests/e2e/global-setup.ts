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
    // The step-up challenge is deliberately NOT confirmed here — it expires in 900s and this job runs far
    // longer than that. `tests/e2e/support/console.ts` clears it per navigation instead.
    const centralOrigin = baseURL.replace('acme.', '');
    const consolePage = await browser.newPage({ ignoreHTTPSErrors: true });

    await consolePage.goto(`${centralOrigin}/login`, { waitUntil: 'networkidle' });
    await consolePage.getByLabel('Email', { exact: true }).fill('console@meridian.test');
    await consolePage.getByLabel('Password', { exact: true }).fill('meridian-console-2026');
    await consolePage.getByRole('button', { name: 'Sign in' }).click();
    // No TOTP hop: the seeded operator has `two_factor_confirmed_at` set with a NULL secret, so Fortify
    // does not consider two-factor ENABLED and issues no challenge, while `EnsureSuperAdminMfa` — which
    // reads only the timestamp — lets the console through. E2eSeeder::seedSuperAdmin() explains the trade.
    await consolePage.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 });

    await consolePage.context().storageState({ path: 'tests/e2e/.auth/admin.json' });

    await browser.close();
}

export default globalSetup;
