import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { acquireScreen, GRAB_CAP_MS, GRAB_MIN_WAIT_MS, GRAB_NUDGE_MS, GRAB_QUIET_MS } from './screenshot';

/**
 * M176 (`R-8496edfa`) — the frame drawn is one the browser CAPTURED after the feedback panel left.
 *
 * The user's check of `M175` on staging: with reduced motion on, a real capture sometimes still held the panel (3 of 13 in
 * a throwaway real-capture probe). `grab()` waited for two presented frames, at most 150ms each — but a tab capture
 * reaches the `<video>` a few frames late, and with reduced motion the panel leaves in about 1ms, so a frame captured
 * BEFORE it left could be the one drawn. This file had no test at all; `FeedbackButton.test.ts` mocks this module whole.
 *
 * The video, its frames and the canvas are faked: what is asserted is which frame `grab()` waits for, never what a browser
 * paints. The real-browser proof is `tests/e2e/feedback-capture-real.spec.ts`.
 */

type FrameCallback = (now: number, metadata: { captureTime?: number }) => void;

let frameCallbacks: FrameCallback[] = [];
const drawImage = vi.fn();

/** Present one frame to whoever is waiting for the next, as `requestVideoFrameCallback` does. */
function presentFrame(metadata: { captureTime?: number } = {}): void {
    const waiting = frameCallbacks;
    frameCallbacks = [];
    waiting.forEach((callback) => callback(performance.now(), metadata));
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'performance', 'Date', 'requestAnimationFrame'] });
    frameCallbacks = [];
    drawImage.mockReset();
    Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true });
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getDisplayMedia: vi.fn(() => Promise.resolve({ getTracks: () => [{ stop: vi.fn() }] })) },
    });
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(() => Promise.resolve());
    Object.defineProperty(HTMLVideoElement.prototype, 'videoWidth', { configurable: true, get: () => 1280 });
    Object.defineProperty(HTMLVideoElement.prototype, 'videoHeight', { configurable: true, get: () => 720 });
    Object.defineProperty(HTMLVideoElement.prototype, 'srcObject', { configurable: true, get: () => null, set: () => undefined });
    (HTMLVideoElement.prototype as unknown as { requestVideoFrameCallback: (callback: FrameCallback) => number }).requestVideoFrameCallback =
        function (callback: FrameCallback): number {
            frameCallbacks.push(callback);
            return frameCallbacks.length;
        };
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => ({ drawImage }) as unknown as CanvasRenderingContext2D);
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (callback: BlobCallback) {
        callback(new Blob(['png'], { type: 'image/png' }));
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (HTMLVideoElement.prototype as unknown as { requestVideoFrameCallback?: unknown }).requestVideoFrameCallback;
});

async function grabbing(since: number): Promise<{ done: () => boolean; file: Promise<File | null> }> {
    const screen = await acquireScreen();
    expect(screen).not.toBeNull();
    let finished = false;
    const file = screen!.grab(since).then((result) => {
        finished = true;
        return result;
    });
    await vi.advanceTimersByTimeAsync(0);

    return { done: () => finished, file };
}

describe('grab() — a frame captured after the panel left', () => {
    it('takes a clean frame that came BEFORE it was asked for — the frame chain runs from the start', async () => {
        // Measured (M176 probes): a detached <video> takes up a new frame only while a frame callback waits for one. The
        // clean frame often lands while the panel is still stepping aside; with no callback pending it went untaken, the
        // page sent nothing more, and the stale frame — panel and all — was drawn.
        const since = performance.now();
        const screen = await acquireScreen();
        presentFrame({ captureTime: since + 3 }); // arrives before grab() is called

        let finished = false;
        void screen!.grab(since).then(() => (finished = true));
        await vi.advanceTimersByTimeAsync(0);

        expect(finished).toBe(true);
        expect(drawImage).toHaveBeenCalledTimes(1);
    });

    it('asks the page for a fresh frame when none captured after the panel left has come', async () => {
        const since = performance.now();
        const { done } = await grabbing(since);
        presentFrame({ captureTime: since - 5 }); // the browser stamps its frames, and this one is too early

        await vi.advanceTimersByTimeAsync(GRAB_NUDGE_MS + 5);
        expect(document.querySelector('[data-capture-nudge]'), 'a one-pixel repaint asked for').not.toBeNull();

        presentFrame({ captureTime: since + GRAB_NUDGE_MS }); // the frame that repaint caused
        await vi.advanceTimersByTimeAsync(0);
        expect(done()).toBe(true);
        await vi.advanceTimersByTimeAsync(50);
        expect(document.querySelector('[data-capture-nudge]'), 'and it is gone again').toBeNull();
    });

    it('never draws a frame the browser captured before the panel left, however many arrive', async () => {
        const since = performance.now();
        const { done, file } = await grabbing(since);

        // Late frames of the page WITH the panel: captured before `since`, presented after it.
        for (let i = 0; i < 6; i++) {
            presentFrame({ captureTime: since - 40 + i });
            await vi.advanceTimersByTimeAsync(30);
        }
        expect(done()).toBe(false);
        expect(drawImage).not.toHaveBeenCalled();

        presentFrame({ captureTime: since + 2 });
        await vi.advanceTimersByTimeAsync(0);

        expect(done()).toBe(true);
        expect(drawImage).toHaveBeenCalledTimes(1);
        expect(await file).toBeInstanceOf(File);
    });

    it('without a capture time, waits a minimum and then for the frames to settle', async () => {
        const since = performance.now();
        const { done } = await grabbing(since);

        // Frames keep arriving for a while after the panel left: the stream is still catching up.
        for (let elapsed = 0; elapsed < GRAB_MIN_WAIT_MS + 100; elapsed += 40) {
            presentFrame();
            await vi.advanceTimersByTimeAsync(40);
            expect(done()).toBe(false);
        }

        // Then quiet — and only a quiet spell lets it draw.
        await vi.advanceTimersByTimeAsync(GRAB_QUIET_MS + 30);
        expect(done()).toBe(true);
        expect(drawImage).toHaveBeenCalledTimes(1);
    });

    it('does not wait past its cap when no frame after the panel left ever comes', async () => {
        const since = performance.now();
        const { done } = await grabbing(since);

        presentFrame({ captureTime: since - 10 });
        await vi.advanceTimersByTimeAsync(GRAB_CAP_MS - 50);
        expect(done()).toBe(false);

        await vi.advanceTimersByTimeAsync(100);
        expect(done()).toBe(true);
    });
});
