import { defineConfig, devices } from '@playwright/test';

// The composed-page responsive + axe gate (PRD Feature #5 / metric G6; DSR §4.6 / decision #10).
// Drives the real running app at the three reference widths and asserts zero WCAG 2.2 AA violations
// and no horizontal overflow on the assembled tenant pages — the integration-level a11y the per-story
// Storybook axe run can't see. Central-domain admin pages ARE scanned as of I10e, by
// `tests/e2e/admin-console-axe.spec.ts` — this header used to say they were excluded "to avoid
// TOTP-in-CI", which turned out to be unnecessary: `EnsureSuperAdminMfa` reads only
// `two_factor_confirmed_at`, so a seeded operator with that timestamp and a NULL secret passes the gate
// and is never challenged.
//
// Auth is established once by global-setup, which now saves TWO sessions: the E2eSeeder's demo Owner on
// the tenant host (the top-level `storageState` below, used by every project) and the console operator
// on the central host (`.auth/admin.json`, opted into per-file by the console spec). Two sessions and
// not one because SESSION_DOMAIN is null, so the tenant cookie is host-only and never reaches the
// central host. The tenant app is served on a subdomain: acme.{CENTRAL_DOMAIN}. In CI the webServer
// boots `php artisan serve` and /etc/hosts maps acme.meridian.test → 127.0.0.1; locally point
// E2E_BASE_URL at a running stack (e.g. http://acme.localhost:8080) and it is reused.

const baseURL = process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000';

export default defineConfig({
    testDir: './tests/e2e',
    // php artisan serve is single-process — keep requests serialized so it isn't overwhelmed.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    globalSetup: './tests/e2e/global-setup.ts',
    timeout: 60_000,
    use: {
        baseURL,
        storageState: 'tests/e2e/.auth/state.json',
        trace: 'on-first-retry',
        ignoreHTTPSErrors: true,
    },
    projects: [
        { name: 'mobile', use: { ...devices['Desktop Chrome'], viewport: { width: 375, height: 667 } } },
        { name: 'tablet', use: { ...devices['Desktop Chrome'], viewport: { width: 834, height: 1112 } } },
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    ],
    webServer: {
        command: 'php artisan serve --host=0.0.0.0 --port=8000',
        url: `${baseURL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
    },
});
