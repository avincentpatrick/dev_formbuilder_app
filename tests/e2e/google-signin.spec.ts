import { expect, test } from '@playwright/test';

import { centralOrigin, tenantOrigin } from './support/hosts';

/**
 * "Continue with Google" on the two front doors (Increment J3c2 — ADR-0017).
 *
 * ⚠️ THIS SPEC IS MOSTLY A CANARY FOR `global-setup.ts`, AND THAT IS ITS REASON FOR EXISTING.
 * Global setup signs in TWICE before any spec in the suite runs, locating the submit control with
 * `getByRole('button', { name: 'Sign in' })` — NOT exact, so it matches on substring. A second control
 * whose accessible name merely CONTAINS "Sign in" is a strict-mode violation *in setup*, which is not a
 * handful of failures but every spec in the suite with none executed. `AuthLayout.vue` carries the same
 * constraint for the marketing panel, and `docs/ux/exceptions-log.md` #14 and #15 both record it.
 *
 * The button is the one control on these pages that could break that, so the count assertion below is the
 * thing that fails FIRST and legibly if the label ever drifts back to "Sign in with Google".
 *
 * ⚠️ IT IS NEVER CLICKED. There is no Google in CI and there must not be: clicking would leave the origin
 * for `accounts.google.com`, which would either hang on the network or — worse — pass by accident against
 * a consent screen nobody meant to reach. The whole flow behind the href is proved by
 * `tests/Feature/Auth/GoogleSignInWebTest.php` against a recording fake. What only a browser can prove is
 * what this file asserts: that the control is a real link, that it is named what the suite's own setup
 * requires, and that it does not collide.
 *
 * ⚠️ NO SCAN HERE. Login is already in `auth-axe.spec.ts`'s page list and Register is scanned there on
 * the CENTRAL host, so the new control is covered by the accessibility gate for free — adding a second
 * axe run would double the cost to assert the same thing.
 *
 * ⚠️ AND IT TOLERATES THE BUTTON BEING ABSENT. `GoogleSignInGate` closes when the deployment has no
 * Google client, which is a SUPPORTED state and the one CI is normally in — no credentials are configured
 * for the e2e stack. So each case asserts the invariant that must hold either way, and asserts the link's
 * shape only when it is actually rendered. A spec that hard-required the button would fail on a
 * correctly-configured CI and pass only on a developer's machine.
 */

test.use({ storageState: { cookies: [], origins: [] } });

const doors = [
    { name: 'Login', url: `${tenantOrigin}/login` },
    // Central, for `auth-axe.spec.ts`'s reason: `registration.invite_only` defaults TRUE on a subdomain,
    // so /register 404s on the tenant host for a workspace that has not opted in.
    { name: 'Register', url: `${centralOrigin}/register` },
] as const;

for (const door of doors) {
    test(`${door.name} has exactly one "Sign in" control, whatever the Google gate says`, async ({ page }) => {
        await page.goto(door.url, { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { level: 1 }).waitFor();

        // THE CANARY. `global-setup.ts` runs this exact query, non-exactly, before any spec.
        await expect(page.getByRole('button', { name: 'Sign in' })).toHaveCount(
            door.name === 'Login' ? 1 : 0,
        );

        const google = page.getByRole('link', { name: 'Continue with Google' });

        if ((await google.count()) === 0) {
            // The gate is closed — no credentials on this deployment. Then nothing may render the mark
            // either, which is what catches a button that lost its `v-if` but kept its chrome.
            await expect(page.locator('.auth-social')).toHaveCount(0);

            return;
        }

        await expect(google).toHaveCount(1);
        await expect(google).toHaveAttribute('href', '/auth/google/redirect');

        // A real anchor, so its role is `link` — which is what makes it structurally incapable of
        // matching the `getByRole('button', …)` query above even if the label were ever got wrong.
        expect(await google.evaluate((node) => node.tagName)).toBe('A');
    });
}
