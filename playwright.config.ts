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
    // ⛔ A RESULT THAT NEEDED THE RETRY IS RED. `retries: 1` is kept — it still rescues a genuine
    // infrastructure hiccup and it is what produces the `trace: 'on-first-retry'` artefact — but a
    // retry may no longer LAUNDER a failure into a green run. Decision D2 in docs/claims/decisions.md
    // (2026-08-26), taken because it had already cost this project twice: `builder-axe.spec.ts`'s
    // "share panel, live link" failed axe `color-contrast` on the identical rule and the identical
    // element in run 32250476088 (2026-08-19) and again in run 32711202891 (2026-08-24) — five days
    // apart, on two unrelated diffs, neither of which touched a `.vue` or a design-system file — and
    // both merged green reading "550 passed + 1 flaky". That is not an intermittent test. It is a
    // deterministic failure wearing a word that invites dismissal, and the passed count dropping by
    // one while the total holds reads exactly like a test having been silently dropped.
    failOnFlakyTests: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never' }]],
    globalSetup: './tests/e2e/global-setup.ts',
    timeout: 60_000,
    use: {
        baseURL,
        storageState: 'tests/e2e/.auth/state.json',
        trace: 'on-first-retry',
        ignoreHTTPSErrors: true,
        // ⚠️ REDUCED MOTION FROM CONTEXT CREATION, NOT FROM THE FIRST `forceTheme` CALL — and the
        // difference between those two moments is an entire class of axe flake.
        //
        // `theme-overrides.css` collapses every `--mds-duration-*` to 1ms under this media query, so
        // with it set here NO transition in the app can still be in flight when a scan runs. Set
        // later, it cannot help: a CSS transition keeps the `transition-duration` it STARTED with, so
        // changing the value mid-flight leaves the running animation alone. `support/axe.ts`'s
        // `forceTheme` has emulated reduced motion since J1e and its docblock names this exact
        // hazard — but it runs AFTER the test has opened whatever it came to scan, which is too late
        // for anything that began animating on the way in.
        //
        // The measured instance: `builder-axe.spec.ts:198` opens `MdsModal`, whose
        // `.mds-modal-enter-active` fades opacity over `--mds-duration-slow` (400ms). axe reached the
        // page ~355ms in and read the primary action button at ~96.5% opacity, compositing
        // `--mds-primary-600` `#0E6FE8` (4.71:1, PASSING) against the white page as `#1674e9` (4.45,
        // failing): 0.965·0E + 0.035·FF = 16, 0.965·6F + 0.035·FF = 74, 0.965·E8 + 0.035·FF = E9 —
        // exact on all three channels. The backlog row filed it as a contrast defect and it is a
        // TIMING defect; darkening a passing token would have hidden a broken gate.
        //
        // Grepped before setting: no e2e spec in this repo asserts an animation or a transition, and
        // every axe scan already ran under reduced motion for most of its life via `forceTheme`.
        // What changes is only the window BEFORE the first `forceTheme` call.
        //
        // ⚠️ IT GOES UNDER `contextOptions`, NOT BESIDE `baseURL`. A bare `reducedMotion` here fails
        // vue-tsc with TS2769 — it is a `BrowserContextOptions` key, not a top-level `use` key, and
        // Playwright's own documentation for it uses exactly this nesting. The projects below set no
        // `contextOptions` of their own, so this survives into all three viewports.
        contextOptions: { reducedMotion: 'reduce' },
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
