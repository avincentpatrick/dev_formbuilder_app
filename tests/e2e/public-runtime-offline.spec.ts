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
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#0E6FE8');

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

    // Increment I10d — the per-submission list, on the confirmation screen. This is the screen UX §7.1 names
    // ("visible from the form's own completion/home view") and, before I10d, the sync surface was mounted
    // inside the fill session and so was absent from exactly here.
    await expect(page.getByText('My submissions on this device')).toBeVisible();
    await expect(page.getByText(/Saved on this device — will send/i)).toBeVisible();

    // ⚠️ REWRITTEN IN J2e, AND THE ASSERTION IT REPLACES WAS THE DOCTRINE, NOT AN ACCIDENT.
    // This used to read "the reference the list shows must be the one the confirmation screen shows", and
    // further down asserted the code did NOT change across the sync transition. Both were correct under the
    // old design, where the only code that existed was derived on the device from the client uuid.
    //
    // That code was stored nowhere on the server, so a respondent who wrote it down and quoted it to the
    // tenant got nothing back. J2e issues a real `submissions.reference` and relabels the local one as a
    // QUEUE TAG — so the code is now expected to change exactly once, when a provisional label is replaced
    // by a real handle, and the copy on screen says so rather than promising otherwise.
    const queueTag = (await page.locator('.outbox__ref').first().innerText()).trim();
    expect(queueTag).toMatch(/^MER-[0-9A-Z]{6}$/);
    await expect(page.getByText(queueTag).first()).toBeVisible();

    await assertClean(page, 'Public runtime queued confirmation');

    // Reconnect → the app-driven replay driver mints a token and POSTs the queued submission.
    //
    // ⚠️ THIS USED TO ASSERT THE OUTBOX WAS EMPTY, and I10d reverses it deliberately: a delivered row is
    // RETAINED as the respondent's receipt (PRD:223 names `synced` as a state they must SEE). The replacement
    // is strictly stronger — it proves delivery, retention AND the PII scrub in one wait, where the old
    // count-is-zero could not distinguish "sent" from "dropped on the floor".
    await context.setOffline(false);
    await page.waitForFunction(
        () =>
            new Promise<boolean>((resolve) => {
                const request = indexedDB.open('meridian-offline');
                request.onsuccess = () => {
                    const db = request.result;
                    if (!db.objectStoreNames.contains('outbox')) {
                        resolve(false);
                        return;
                    }
                    const all = db.transaction('outbox', 'readonly').objectStore('outbox').getAll();
                    all.onsuccess = () => {
                        const rows = all.result as Array<{
                            status: string;
                            server_submission_id: string | null;
                            answers: Record<string, unknown>;
                        }>;
                        resolve(
                            rows.length === 1 &&
                                rows[0].status === 'synced' &&
                                typeof rows[0].server_submission_id === 'string' &&
                                rows[0].server_submission_id.length > 0 &&
                                Object.keys(rows[0].answers ?? {}).length === 0,
                        );
                    };
                    all.onerror = () => resolve(false);
                };
                request.onerror = () => resolve(false);
            }),
        null,
        { timeout: 20_000 },
    );

    // ...and the row now shows the SERVER's reference, which is a DIFFERENT string from the queue tag it
    // showed while pending. That difference is the whole point of J2e: the tag was a device-local label, the
    // reference is a row the tenant can actually find.
    //
    // Scoped to the row's own paragraph, and the live region asserted separately, because a bare
    // getByText() matches BOTH: the receipt reads "Sent — reference 7K4M-2QXB" and the polite announcement
    // reads "Response sent — reference 7K4M-2QXB", which contains it. Two elements is a strict-mode
    // violation, and the fix is to say which one — asserting both is stronger than picking one with
    // .first() anyway, since it pins that the visible receipt and the screen-reader announcement quote the
    // same code.
    const receipt = page.locator('.outbox__detail', { hasText: /^Sent — reference / });
    await expect(receipt).toBeVisible({ timeout: 20_000 });

    const sentText = (await receipt.innerText()).trim();
    const reference = sentText.replace('Sent — reference ', '').trim();

    // The shape the server issues: eight Crockford Base32 characters, displayed in two groups of four. The
    // alphabet excludes I, L, O and U, which is why the class is spelled out rather than written [A-Z0-9].
    expect(reference).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);
    expect(reference).not.toBe(queueTag);

    await expect(page.locator('.sync-status__sr')).toHaveText(`Response sent — reference ${reference}`);
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
    // The BANNER's Review, by testid. I10d gave each conflict ROW its own Review button too, so a bare
    // getByRole('button', { name: 'Review' }) now matches two elements and dies on Playwright's strict mode.
    const reviewButton = page.getByTestId('review-conflicts');
    await expect(reviewButton).toBeVisible({ timeout: 15_000 });
    // `needs?` — I10d pluralises the badge, so a single conflict now reads "1 needs review" and the old
    // contiguous /need review/ no longer matches. The row copy below it says "Needs review" too.
    await expect(page.getByText(/needs? review/i).first()).toBeVisible();

    // I10d — a conflict row NEVER offers a blind retry. A parked 409 either 409s again or is re-parked by
    // the version guard before the POST, so the button would be a lie.
    await expect(page.getByRole('button', { name: 'Retry now' })).toHaveCount(0);
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

    // The outbox is STILL empty here, and that is not an oversight after I10d's retention change: the
    // resolve-resubmit goes out through RuntimeSession's ONLINE path, which discards the intent record
    // rather than retaining a receipt (see the comment on that call). Only a REPLAYED submission leaves one.
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
