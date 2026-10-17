import { expect, test, type Page } from '@playwright/test';

/**
 * M176 (`R-8496edfa`, ADR-0015 §D11) — a REAL tab capture never holds the feedback panel.
 *
 * The user's check of `M175` on staging (2026-10-17): with reduced motion on, a real capture sometimes still showed the
 * panel — 3 of 13 in a throwaway real-capture probe, 0 of 13 without reduced motion — because a tab capture reaches the
 * page's <video> a few frames late and the panel leaves in about 1ms, so `grab()` could draw a frame captured before it
 * left. `feedback-capture.spec.ts` cannot see that: it records whether a dialog is in the DOM when a frame is drawn, off a
 * canvas stream with no latency — never what the frame SHOWS. This spec reads the picture itself.
 *
 * ── HOW IT CAPTURES FOR REAL ─────────────────────────────────────────────────────────────────────────────────────────
 * The full Chromium build (`channel: 'chromium'`; the default headless shell has no tab capture) with
 * `--auto-accept-this-tab-capture`, which answers the browser's own share picker with this tab. The panel is painted
 * magenta, so a picture that holds it is told apart by its pixels. The project runs with `reducedMotion: 'reduce'` — the
 * condition the race needs.
 *
 * ⚠️ LOCAL ONLY, SAID SO RATHER THAN HIDDEN. A capture needs a secure context: `acme.localhost` always is one; CI's e2e
 * host is plain http on another name, where the browser offers no `getDisplayMedia` and the insecure-origin flag does not
 * take (measured by `M175`, see `feedback-capture.spec.ts`). So CI skips it, as `public-runtime-offline.spec.ts` skips its
 * service-worker cases. Desktop only: one viewport is the measurement, and the probe's numbers were taken at 1440×900.
 * Run it with the e2e container: `docker compose run --rm e2e test tests/e2e/feedback-capture-real.spec.ts --project=desktop`.
 */

test.use({ channel: 'chromium', launchOptions: { args: ['--auto-accept-this-tab-capture'] } });

/** At the probe's measured rate (3 in 13), twenty captures hold the panel at least once with about 99% odds. */
const CAPTURES = 20;

const PANEL_MAGENTA = '[role="dialog"], [role="dialog"] * { background-color: #ff00ff !important; }';

/** How much of the attached picture is the panel's magenta. */
async function panelShare(page: Page): Promise<number> {
    return page.evaluate(async () => {
        const img = document.querySelector<HTMLImageElement>('img[alt="Preview of the screenshot you attached"]');
        if (img === null) return -1;
        await img.decode().catch(() => undefined);
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const context = canvas.getContext('2d');
        if (context === null || canvas.width === 0) return -1;
        context.drawImage(img, 0, 0);
        const data = context.getImageData(0, 0, canvas.width, canvas.height).data;
        let magenta = 0;
        for (let i = 0; i < data.length; i += 4) {
            if (data[i] > 200 && data[i + 1] < 70 && data[i + 2] > 200) magenta++;
        }

        return magenta / (data.length / 4);
    });
}

test('a real tab capture never holds the feedback panel, with reduced motion on', async ({ page }, info) => {
    test.skip(!!process.env.CI, 'A real tab capture needs a secure context; CI’s e2e host is plain http (see the header).');
    test.skip(info.project.name !== 'desktop', 'One viewport is the measurement (the probe ran at 1440×900).');
    test.setTimeout(180_000);

    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: PANEL_MAGENTA });

    await page.getByRole('button', { name: 'Send feedback' }).click();
    const dialog = page.getByRole('dialog', { name: 'Send feedback' });
    await expect(dialog).toBeVisible();
    // The precondition: a real capture is on offer here (a secure context, a real getDisplayMedia).
    await expect(dialog.getByRole('button', { name: 'Capture screen' })).toBeVisible();

    const shares: number[] = [];
    for (let i = 0; i < CAPTURES; i++) {
        await dialog.getByRole('button', { name: 'Capture screen' }).click();
        const preview = dialog.getByRole('img', { name: 'Preview of the screenshot you attached' });
        await expect(preview).toBeVisible({ timeout: 15_000 });
        shares.push(await panelShare(page));
        await dialog.getByRole('button', { name: 'Remove' }).click();
        await expect(preview).toHaveCount(0);
    }

    const rounded = shares.map((share) => Math.round(share * 1000) / 10);
    expect(shares.every((share) => share >= 0), `every capture produced a picture: ${rounded.join(', ')}%`).toBe(true);
    // A capture that held the panel shows it whole — about a quarter of the picture in the probe. None may show any.
    expect(shares.filter((share) => share > 0.001), `the panel's share of each picture: ${rounded.join(', ')}%`).toEqual([]);
});
