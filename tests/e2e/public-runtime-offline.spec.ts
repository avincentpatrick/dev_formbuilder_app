import { expect, test } from '@playwright/test';
import { assertClean } from './support/axe';

// Increment G8a — the guest runtime is an installable PWA that renders a previously-loaded form offline.
// This spec bypasses the shared AUTHENTICATED storageState (the guest has no session), proves the
// installability signals (linked per-form manifest + theme-color), primes the service-worker caches under
// control, then goes offline and asserts the form still renders + the offline submit guard fires (no crash),
// scanning the offline state for WCAG 2.2 AA. Precedent: public-runtime-axe.spec.ts.

test.use({ storageState: { cookies: [], origins: [] } });

test('Public runtime — installable PWA renders offline after a prior load', async ({ page, context }) => {
    await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // Installability signals wired into the shell head.
    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
        'href',
        '/f/clinic-intake/manifest.webmanifest',
    );
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#1B5E5E');

    // Wait for the service worker to control the scope, then reload so the shell HTML + hashed assets + the
    // pinned schema all flow through the SW and populate its runtime caches (the first navigation, before
    // the SW activates, is uncontrolled and not cached).
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // Go offline and reload — the form must still render entirely from cache.
    await context.setOffline(true);
    await page.reload({ waitUntil: 'commit' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // The ambient offline pill is shown, and submitting is blocked with a clear message rather than a crash
    // or a generic error (the outbox that would queue the submission is G8b).
    await expect(page.getByText(/offline/i).first()).toBeVisible();
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByText(/reconnect to submit/i)).toBeVisible();

    await assertClean(page, 'Public runtime offline');

    await context.setOffline(false);
});
