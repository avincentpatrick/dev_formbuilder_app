import { expect, test } from '@playwright/test';

/**
 * M175 (`R-2ce2f274`, ADR-0015 §D11) — the feedback panel steps aside for its own screenshot.
 *
 * The user's comment (2026-10-14): *"when i click capture screen, the modal for the feedback is always
 * included"*. The frame was taken while the panel was open. Now the capture acquires the stream inside the
 * click, closes the panel, waits for the modal's leave transition, takes the frame, and reopens the panel
 * with the report as it was. This is the one place a REAL browser decides the order of those steps, so it
 * asserts the thing the user saw: at the moment a frame is drawn off the stream, no dialog is on the page.
 *
 * ── TWO STAND-INS, BOTH NAMED ───────────────────────────────────────────────────────────────────────────
 * · The e2e host is plain http on a non-localhost name, which is not a secure context, so the browser hides
 *   `getDisplayMedia` and the panel hides its Capture button. Chromium is told to treat this one origin as
 *   secure — the `public-runtime-offline.spec.ts` precedent.
 * · A headless browser has no screen picker, so `getDisplayMedia` is replaced by a canvas's own stream, and
 *   `drawImage` is wrapped to record whether a dialog was present each time a VIDEO frame is drawn — the
 *   instant the screenshot is actually taken.
 * The project runs with `reducedMotion: 'reduce'`, so the modal's leave takes about 1ms.
 */

const baseURL = process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000';
const secureOrigin = new URL(baseURL).origin;

test.use({
    launchOptions: { args: [`--unsafely-treat-insecure-origin-as-secure=${secureOrigin}`] },
});

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
        const probe = { dialogAtDraw: [] as boolean[] };
        (window as unknown as { __feedbackProbe: typeof probe }).__feedbackProbe = probe;

        const drawImage = CanvasRenderingContext2D.prototype.drawImage;
        CanvasRenderingContext2D.prototype.drawImage = function (
            this: CanvasRenderingContext2D,
            ...args: Parameters<CanvasRenderingContext2D['drawImage']>
        ) {
            if (args[0] instanceof HTMLVideoElement) {
                probe.dialogAtDraw.push(document.querySelector('[role="dialog"]') !== null);
            }
            return (drawImage as (...a: unknown[]) => void).apply(this, args);
        } as CanvasRenderingContext2D['drawImage'];

        if (navigator.mediaDevices) {
            navigator.mediaDevices.getDisplayMedia = async () => {
                const canvas = document.createElement('canvas');
                canvas.width = 640;
                canvas.height = 360;
                const context = canvas.getContext('2d')!;
                const paint = (): void => {
                    context.fillStyle = '#334';
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    context.fillStyle = '#fff';
                    context.fillText(String(performance.now()), 12, 24);
                    requestAnimationFrame(paint);
                };
                paint();
                return canvas.captureStream(30);
            };
        }
    });
});

test('the feedback panel is off the screen when the screenshot is taken, and comes back with the report', async ({ page }) => {
    await page.goto('/dashboard', { waitUntil: 'networkidle' });

    await page.getByRole('button', { name: 'Send feedback' }).click();
    const dialog = page.getByRole('dialog', { name: 'Send feedback' });
    await expect(dialog).toBeVisible();

    await dialog.getByRole('radio', { name: 'Bug' }).check();
    await dialog.getByRole('textbox', { name: "What's on your mind?" }).fill('The export button does nothing');

    // The precondition the two stand-ins exist for: without a secure context there is no Capture button.
    await dialog.getByRole('button', { name: 'Capture screen' }).click();

    await expect(dialog.getByRole('img', { name: 'Preview of the screenshot you attached' })).toBeVisible();

    const dialogAtDraw = await page.evaluate(
        () => (window as unknown as { __feedbackProbe: { dialogAtDraw: boolean[] } }).__feedbackProbe.dialogAtDraw,
    );
    expect(dialogAtDraw.length, 'a frame was drawn off the stream').toBeGreaterThan(0);
    expect(dialogAtDraw, 'no frame was taken with the panel on the page').not.toContain(true);

    // …and the reporter finds what they typed and chose exactly where they left it.
    await expect(dialog.getByRole('textbox', { name: "What's on your mind?" })).toHaveValue('The export button does nothing');
    await expect(dialog.getByRole('radio', { name: 'Bug' })).toBeChecked();
});
