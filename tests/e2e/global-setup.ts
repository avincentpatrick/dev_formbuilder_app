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
    await browser.close();
}

export default globalSetup;
