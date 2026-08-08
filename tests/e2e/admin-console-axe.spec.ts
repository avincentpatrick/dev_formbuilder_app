import { test } from '@playwright/test';
import { assertClean, forceTheme } from './support/axe';
import { openConsole } from './support/console';

/*
 * The central-domain super-admin console (Increment I10e), closing the `docs/feature-backlog.md` row that
 * `responsive-axe.spec.ts` has carried since I8c.
 *
 * ── WHY THIS COULD NOT SIMPLY LIVE IN responsive-axe ──────────────────────────────────────────────────
 * Three reasons, and only one of them was the TOTP the row blamed. (1) `playwright.config.ts` sets
 * `use.storageState` at the TOP level, so every project is authenticated as the TENANT owner — and
 * `SESSION_DOMAIN` is null, so that cookie is host-only and is never sent to the central host anyway.
 * (2) The console operator needs `is_super_admin`, which the demo Owner does not have. (3) Every console
 * route carries `superadmin.mfa` AND `step-up`.
 *
 * The fix for (1) is the per-file `test.use({ storageState })` idiom `auth-axe.spec.ts` already proved,
 * pointed at a second state file rather than at an empty one — NOT the config restructure the backlog row
 * assumed. (2) is one idempotent seeder method. (3) turned out to need no crypto at all: see
 * `E2eSeeder::seedSuperAdmin()` for why a confirmed-but-secretless operator passes the MFA gate without
 * ever being challenged, and `support/console.ts` for why the step-up is cleared per navigation instead of
 * being baked into the stored session.
 *
 * ── SCOPE, NARROWED ON PURPOSE ────────────────────────────────────────────────────────────────────────
 * The two pages `responsive-axe.spec.ts` explicitly names as uncovered: `/admin/settings` and
 * `/admin/feedback`. The remaining console pages (tenants, tenant detail, users, audit log) stay on the
 * backlog row rather than being swept in — `AdminLayout` has never been scanned at ANY viewport, so this is
 * the first measurement of it and the first run may legitimately be red. Twelve tests (2 pages × 2 themes ×
 * the config's 3 viewport projects) is about a minute on a ~13-minute job; four more pages would triple
 * that before anyone knows whether the shell is clean.
 *
 * The scans are GOTO-ONLY — no dialogs opened — so this file stays independent of I10a's modal work.
 */

test.use({ storageState: 'tests/e2e/.auth/admin.json' });

const pages = [
    // The platform settings surface — toggles, plan catalogue, maintenance mode.
    { name: 'Admin settings', path: '/admin/settings' },
    // The feedback console (I7a) — a DataTable plus its status filters, i.e. the densest console page.
    { name: 'Admin feedback', path: '/admin/feedback' },
] as const;

const themes = ['light', 'dark'] as const;

for (const p of pages) {
    for (const theme of themes) {
        test(`${p.name} (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
            await openConsole(page, p.path);
            await forceTheme(page, theme);
            await assertClean(page, p.name);
        });
    }
}
