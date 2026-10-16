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

/** How long {@see ScreenCapture.grab} waits for each fresh frame before drawing whatever it has. */
const FRAME_WAIT_MS = 150;

/** A live capture: take one frame when the page looks right, then stop. */
export interface ScreenCapture {
    /** Draw the newest frame and encode it as a PNG File; `null` if the stream never produced a picture. */
    grab(): Promise<File | null>;
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

    await video.play();

    let stopped = false;
    const stop = (): void => {
        if (stopped) return;
        stopped = true;
        video.pause();
        video.srcObject = null;
        stream.getTracks().forEach((track) => track.stop());
    };

    return {
        stop,
        async grab(): Promise<File | null> {
            // Two fresh frames before drawing. The page changed a moment ago (the panel left), and a
            // captured surface reaches the <video> a frame or two late — the frame already showing may
            // still hold the panel. Each wait is capped, because a static page may deliver no new frame.
            await nextFrame(video);
            await nextFrame(video);

            return frameToPngFile(video);
        },
    };
}

/** Resolve on the next frame the video presents, or after {@see FRAME_WAIT_MS}, whichever comes first. */
function nextFrame(video: HTMLVideoElement): Promise<void> {
    return new Promise<void>((resolve) => {
        const timer = window.setTimeout(resolve, FRAME_WAIT_MS);
        const withCallback = video as HTMLVideoElement & {
            requestVideoFrameCallback?: (callback: () => void) => number;
        };

        if (typeof withCallback.requestVideoFrameCallback === 'function') {
            withCallback.requestVideoFrameCallback(() => {
                window.clearTimeout(timer);
                resolve();
            });
        } else {
            requestAnimationFrame(() => requestAnimationFrame(() => {
                window.clearTimeout(timer);
                resolve();
            }));
        }
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
