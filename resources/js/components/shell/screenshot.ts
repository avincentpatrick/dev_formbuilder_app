/**
 * One-click screenshot capture for the feedback panel (PRD Feature #11, Increment I7a / ADR-0015).
 *
 * Uses the browser's native Screen Capture API — `navigator.mediaDevices.getDisplayMedia()` — rather than
 * a DOM-snapshot library. The PRD left this open pending a spike; ADR-0015 records the decision and its
 * reasoning in full. The short version: a DOM snapshot renders the Leaflet map picker and the signature
 * canvas BLANK, which are exactly the screens a bug report tends to be about, and it would add a ~1 MB
 * production dependency (last released 2022) to the bundle the npm-audit gate scans. This adds nothing.
 *
 * The cost is a permission prompt per capture, which is why {@see isCaptureSupported} exists and why the
 * panel always offers a plain file input beside the capture button: declining, dismissing the picker, or
 * running a browser without the API must all cost the reporter nothing but the screenshot.
 *
 * ── TWO STEPS, NOT ONE (M175, `R-2ce2f274`, ADR-0015 §D11) ─────────────────────────────────────────────
 * The frame used to be taken the moment the stream arrived — with the feedback panel still open, so the
 * panel was in every screenshot. Capture is now split: {@see acquireScreen} asks for the stream INSIDE the
 * click (the browser grants the picker only to a user gesture), and the caller then gets the panel off the
 * page before it calls {@see ScreenCapture.grab}. Acquiring first means nothing depends on how long the
 * click's permission lasts — the panel may take as long as it needs to leave.
 */

/** The longest edge we encode. A raw 4K frame is ~8 MB of PNG; 1600px keeps text legible at a fraction. */
const MAX_WIDTH = 1600;

/*
 * ── WHICH FRAME IS DRAWN (M176, `R-8496edfa`, ADR-0015 §D11) ───────────────────────────────────────────────────────────
 * `grab()` used to wait for two presented frames, at most 150ms each, once the panel had left. The user's check of `M175`
 * found a real capture that still held the panel, with reduced motion on — 3 of 13 in a throwaway probe, then 4 of 20
 * in `feedback-capture-real.spec.ts` on this code. Measured with five more probes (M176), two causes, both fixed here:
 *  1. A tab capture reaches the <video> a few frames late, so the frame showing may have been captured BEFORE the panel
 *     left. Now the frame drawn is one the browser says it CAPTURED after the panel left (`requestVideoFrameCallback`'s
 *     `captureTime`, in the `performance.now()` timeline — Chrome stamps every tab-capture frame with it).
 *  2. ⛔ A detached <video> TAKES UP a new frame only while a frame callback is waiting for one. The clean frame arrives
 *     30–170ms after the panel leaves; if no callback was pending just then, it was never taken up, the page sent
 *     nothing more (nothing on it changed), and the stale frame — panel and all — was drawn. So a callback chain runs
 *     from the moment the stream plays until it stops, and always knows the newest frame and when it was captured.
 * A clean frame captured within a frame of the panel leaving can still be passed over (the compositor may lag the DOM by a
 * frame, so `since` is taken after it), so when no frame captured after `since` has come within {@see GRAB_NUDGE_MS} the
 * page is nudged into sending one: a single near-transparent pixel, shown for one frame. Where a browser gives no capture
 * time, the frames are waited out: at least {@see GRAB_MIN_WAIT_MS} after the panel left, then a quiet spell of
 * {@see GRAB_QUIET_MS}. Never longer than {@see GRAB_CAP_MS}: the reporter is waiting.
 */
export const GRAB_MIN_WAIT_MS = 250;
export const GRAB_QUIET_MS = 150;
export const GRAB_NUDGE_MS = 120;
export const GRAB_CAP_MS = 750;
const GRAB_POLL_MS = 25;

/** A live capture: take one frame when the page looks right, then stop. */
export interface ScreenCapture {
    /**
     * Draw a frame captured after `since` (a `performance.now()` time — when the panel left the page; now, if not given)
     * and encode it as a PNG File; `null` if the stream never produced a picture.
     */
    grab(since?: number): Promise<File | null>;
    /** Stop every track. Idempotent. */
    stop(): void;
}

/**
 * Whether this browser can capture at all. Two gates, both real: the API is absent on iOS Safari
 * entirely, and it is a secure-context-only API — so on plain http (anything but localhost) the property
 * exists on the prototype but `navigator.mediaDevices` itself is undefined.
 */
export function isCaptureSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.isSecureContext === true &&
        typeof navigator?.mediaDevices?.getDisplayMedia === 'function'
    );
}

/**
 * Ask for the screen surface the user picks and start it playing, or resolve `null` if they declined or
 * dismissed the picker. Call it straight from the click handler.
 *
 * Only a genuine failure throws. A decline is `NotAllowedError` and is NOT an error condition in this
 * feature — it is the user exercising the choice the permission prompt exists to offer — so it resolves
 * null and the caller simply carries on without an image.
 */
export async function acquireScreen(): Promise<ScreenCapture | null> {
    if (!isCaptureSupported()) {
        return null;
    }

    let stream: MediaStream | null = null;

    try {
        stream = await navigator.mediaDevices.getDisplayMedia({
            // `preferCurrentTab` is Chromium-only and purely a courtesy: it puts this tab first in the
            // picker so the common case is one click. Firefox/Safari ignore the unknown key.
            video: { displaySurface: 'browser' },
            audio: false,
            preferCurrentTab: true,
        } as DisplayMediaStreamOptions);

        return await startCapture(stream);
    } catch (error) {
        // Stop every track on the way out. Leaving one running keeps the browser's "sharing your screen"
        // indicator up for the rest of the session, which reads as a bug and is one.
        stream?.getTracks().forEach((track) => track.stop());

        if (error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
            return null;
        }

        throw error;
    }
}

async function startCapture(stream: MediaStream): Promise<ScreenCapture> {
    const video = document.createElement('video');
    video.srcObject = stream;
    video.muted = true;

    // The frame chain (cause 2 above): registered before playback starts, so no frame of this stream goes untaken.
    const frames: FrameLog = { latest: null, timed: false, listeners: new Set() };
    const withCallback = video as WithFrameCallback;
    let stopped = false;
    if (false && typeof withCallback.requestVideoFrameCallback === 'function') {
        const onFrame = (_now: number, metadata: FrameMetadata): void => {
            if (stopped) return;
            const captureTime = typeof metadata?.captureTime === 'number' ? metadata.captureTime : null;
            frames.latest = { at: performance.now(), captureTime };
            if (captureTime !== null) frames.timed = true;
            frames.listeners.forEach((listener) => listener());
            withCallback.requestVideoFrameCallback?.(onFrame);
        };
        withCallback.requestVideoFrameCallback(onFrame);
    }

    await video.play();

    const stop = (): void => {
        if (stopped) return;
        stopped = true;
        video.pause();
        video.srcObject = null;
        stream.getTracks().forEach((track) => track.stop());
    };

    return {
        stop,
        async grab(since: number = performance.now()): Promise<File | null> {
            await capturedAfter(frames, since);

            return frameToPngFile(video);
        },
    };
}

type FrameMetadata = { captureTime?: number };
type WithFrameCallback = HTMLVideoElement & {
    requestVideoFrameCallback?: (callback: (now: number, metadata: FrameMetadata) => void) => number;
};

/** What the frame chain knows: the newest frame taken up, and whether this browser stamps frames with a capture time. */
interface FrameLog {
    latest: { at: number; captureTime: number | null } | null;
    timed: boolean;
    listeners: Set<() => void>;
}

/** One near-transparent pixel for one frame: enough for the page to send a fresh frame, invisible in the picture. */
function nudgeRepaint(): void {
    if (typeof document === 'undefined') return;
    const pixel = document.createElement('div');
    pixel.setAttribute('aria-hidden', 'true');
    pixel.dataset.captureNudge = '';
    pixel.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:1px;background:rgba(0,0,0,0.004);pointer-events:none;z-index:2147483647';
    document.body.appendChild(pixel);
    requestAnimationFrame(() => requestAnimationFrame(() => pixel.remove()));
}

/**
 * Resolve once the newest frame was captured after `since` — or, where the browser gives no capture time, once the frames
 * have settled — and never later than {@see GRAB_CAP_MS}. See the timing note at the top of this file.
 */
function capturedAfter(frames: FrameLog, since: number): Promise<void> {
    return new Promise<void>((resolve) => {
        const startedAt = performance.now();
        let finished = false;

        function check(): void {
            if (finished) return;
            const latest = frames.latest;
            if (frames.timed) {
                if (latest?.captureTime !== null && latest?.captureTime !== undefined && latest.captureTime >= since) finish();
                return;
            }
            const now = performance.now();
            if (now - since >= GRAB_MIN_WAIT_MS && now - (latest?.at ?? startedAt) >= GRAB_QUIET_MS) finish();
        }

        function finish(): void {
            if (finished) return;
            finished = true;
            window.clearTimeout(cap);
            window.clearTimeout(nudge);
            window.clearInterval(poll);
            frames.listeners.delete(check);
            resolve();
        }

        const cap = window.setTimeout(finish, GRAB_CAP_MS);
        const nudge = window.setTimeout(() => {
            if (!finished && frames.timed) nudgeRepaint();
        }, GRAB_NUDGE_MS);
        const poll = window.setInterval(check, GRAB_POLL_MS);
        frames.listeners.add(check);
        check(); // the frame wanted may already be the newest one
    });
}

/** Draw the video's current frame and encode it. */
async function frameToPngFile(video: HTMLVideoElement): Promise<File | null> {
    const sourceWidth = video.videoWidth;
    const sourceHeight = video.videoHeight;

    // Without a picture yet `videoWidth` is 0 and the canvas would be drawn empty — the classic
    // silent-blank-screenshot bug.
    if (sourceWidth === 0 || sourceHeight === 0) {
        return null;
    }

    const scale = Math.min(1, MAX_WIDTH / sourceWidth);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(sourceWidth * scale);
    canvas.height = Math.round(sourceHeight * scale);

    const context = canvas.getContext('2d');
    if (context === null) {
        return null;
    }

    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));

    if (blob === null) {
        return null;
    }

    // A File rather than the raw Blob: Inertia's useForm switches to multipart on either, but only a File
    // carries a name, which becomes the attachment's `original_filename` (display metadata only — it never
    // touches the storage key).
    return new File([blob], 'screenshot.png', { type: 'image/png' });
}
