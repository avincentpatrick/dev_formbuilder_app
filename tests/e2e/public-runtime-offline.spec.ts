import { expect, test } from '@playwright/test';
import { assertClean } from './support/axe';

// Increment G8a — the guest runtime is an installable PWA that renders a previously-loaded form offline.
// This spec bypasses the shared AUTHENTICATED storageState (the guest has no session).
//
// Service workers only run in a SECURE CONTEXT (https or localhost). The e2e app is served over plain HTTP
// on a non-localhost subdomain, so we launch Chromium treating that exact origin as secure — this makes the
// SW register and lets us prove the true offline RENDER. If that ever fails to take effect, the SW-render
// leg is guarded (skipped) rather than failing; the installability signals + the SW-independent offline
// submit guard are always asserted. Precedent: public-runtime-axe.spec.ts.

const baseURL = process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000';
const secureOrigin = new URL(baseURL).origin;

test.use({
    storageState: { cookies: [], origins: [] },
    launchOptions: { args: [`--unsafely-treat-insecure-origin-as-secure=${secureOrigin}`] },
});

test('Public runtime — installable PWA renders offline + guards submit', async ({ page, context }) => {
    await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // Installability signals wired into the shell head (origin-independent). The `?b=` fingerprint (H23b)
    // is the manifest's cache-invalidation lever; the E2E tenant is deliberately unbranded, so it reads
    // `none` and `theme-color` is the product default (`--mds-primary-600`, the light
    // `--mds-color-action-primary-bg`). Seeding a brand here would move the axe contrast baselines for a
    // property the ramp engine already measures against all seventeen §4.1 pairings before storing.
    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
        'href',
        '/f/clinic-intake/manifest.webmanifest?b=none',
    );
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#1C4B72');

    // If the service worker is available (secure context), prime its caches under control and prove the form
    // still RENDERS offline. `navigator.serviceWorker` is only defined in a secure context.
    const swAvailable = await page.evaluate(() => 'serviceWorker' in navigator);
    if (swAvailable) {
        await page.evaluate(async () => {
            await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((resolve) => setTimeout(resolve, 10_000)),
            ]);
        });
        // Reload so the shell HTML + hashed assets + pinned schema all flow through the SW and cache (the
        // first navigation, before the SW activates, is uncontrolled and not cached). Each mint embeds a
        // fresh token, but the cached shell HTML pins its token, so the schema URL it fetches matches the
        // cached schema entry — offline render stays self-consistent.
        await page.reload({ waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { name: 'Clinic Intake', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });

        // Deterministically wait until the runtime caches actually hold the shell HTML + schema before
        // cutting the network, so the offline reload can't race an unpopulated cache.
        await page.waitForFunction(
            async () => {
                const needed = ['guest-shell-html', 'guest-schema'];
                const names = await caches.keys();
                for (const name of needed) {
                    if (!names.includes(name)) {
                        return false;
                    }
                    const entries = await (await caches.open(name)).keys();
                    if (entries.length === 0) {
                        return false;
                    }
                }
                return true;
            },
            null,
            { timeout: 15_000 },
        );

        await context.setOffline(true);
        await page.reload({ waitUntil: 'commit' });
        await page
            .getByRole('heading', { name: 'Clinic Intake', level: 1 })
            .waitFor({ state: 'visible', timeout: 15_000 });
    } else {
        // No SW on this origin — just drop the connection on the already-loaded page (no offline reload).
        await context.setOffline(true);
    }

    // Increment G8b — the Dexie outbox replaces the G8a hard block. The ambient offline pill is SW-independent
    // (navigator.onLine / the offline event); an offline submit is now QUEUED on the device with a "saved on
    // this device" confirmation (clinic-intake has no required fields, so an empty submit passes the gate).
    await expect(page.getByText(/offline/i).first()).toBeVisible();
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByRole('heading', { name: /saved on this device/i })).toBeVisible({ timeout: 15_000 });

    await assertClean(page, 'Public runtime queued confirmation');

    // Reconnect → the app-driven replay driver mints a token, POSTs the queued submission, and drains the
    // Dexie outbox. Assert the outbox empties (the row synced), reading IndexedDB directly.
    await context.setOffline(false);
    await page.waitForFunction(
        () =>
            new Promise<boolean>((resolve) => {
                const request = indexedDB.open('meridian-offline');
                request.onsuccess = () => {
                    const db = request.result;
                    if (!db.objectStoreNames.contains('outbox')) {
                        resolve(true);
                        return;
                    }
                    const count = db.transaction('outbox', 'readonly').objectStore('outbox').count();
                    count.onsuccess = () => resolve(count.result === 0);
                    count.onerror = () => resolve(false);
                };
                request.onerror = () => resolve(false);
            }),
        null,
        { timeout: 20_000 },
    );
});

// Increment G8c — the 409 conflict-resolution UX. The replay→409→park-as-conflict path itself is covered by
// the Vitest replay suite; this proves the RESOLUTION half end-to-end against the real server. We produce a
// genuine queued row through the real offline-submit flow (which safely creates the Dexie schema), then flip
// its status to `conflict` — a faithful stand-in for "a republish superseded the queued version" — without
// needing a mid-test republish. Then: Review → re-mint + re-fetch the live schema → resubmit → the parked row
// is discarded and the resubmission syncs.
test('Public runtime — reviews & resolves a parked conflict (Increment G8c)', async ({ page, context }) => {
    await page.goto('/f/clinic-intake', { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // Queue a real submission offline (clinic-intake has no required fields → an empty submit passes the gate).
    // This creates the Dexie `outbox` store + a pending row, so the raw-IDB edit below can't race schema creation.
    await context.setOffline(true);
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByRole('heading', { name: /saved on this device/i })).toBeVisible({ timeout: 15_000 });

    // Flip the queued row to a parked conflict (as the replay engine would after a 409), then reconnect. It is
    // NOT `pending`, so the boot replay won't drain it — it surfaces in the "needs review" banner instead.
    await page.evaluate(
        () =>
            new Promise<void>((resolve, reject) => {
                const open = indexedDB.open('meridian-offline');
                open.onsuccess = () => {
                    const db = open.result;
                    const tx = db.transaction('outbox', 'readwrite');
                    const store = tx.objectStore('outbox');
                    const all = store.getAll();
                    all.onsuccess = () => {
                        for (const row of all.result as Array<Record<string, unknown>>) {
                            row.status = 'conflict';
                            row.conflict_code = 'form_updated';
                            row.last_error = 'This form has been updated.';
                            store.put(row);
                        }
                    };
                    tx.oncomplete = () => resolve();
                    tx.onerror = () => reject(tx.error);
                };
                open.onerror = () => reject(open.error);
            }),
    );

    await context.setOffline(false);
    await page.reload({ waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { name: 'Clinic Intake', level: 1 })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // The conflict banner surfaces a Review CTA.
    const reviewButton = page.getByRole('button', { name: 'Review' });
    await expect(reviewButton).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/need review/i)).toBeVisible();
    await assertClean(page, 'Public runtime conflict banner');

    // Review → the app re-mints + re-fetches the current schema and re-opens the fill with a resolve notice
    // plus a discard escape hatch.
    await reviewButton.click();
    await expect(page.getByText(/this form was updated/i)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('button', { name: /discard this response/i })).toBeVisible();
    await assertClean(page, 'Public runtime conflict resolve');

    // Resubmit → the reviewed answers are recorded and the parked conflict row discarded.
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByRole('heading', { name: /reviewed response has been submitted/i })).toBeVisible({
        timeout: 15_000,
    });

    // The outbox is empty: the parked conflict was discarded and the resubmission synced.
    await page.waitForFunction(
        () =>
            new Promise<boolean>((resolve) => {
                const request = indexedDB.open('meridian-offline');
                request.onsuccess = () => {
                    const db = request.result;
                    if (!db.objectStoreNames.contains('outbox')) {
                        resolve(true);
                        return;
                    }
                    const count = db.transaction('outbox', 'readonly').objectStore('outbox').count();
                    count.onsuccess = () => resolve(count.result === 0);
                    count.onerror = () => resolve(false);
                };
                request.onerror = () => resolve(false);
            }),
        null,
        { timeout: 20_000 },
    );
});
