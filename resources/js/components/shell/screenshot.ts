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
 */

/** The longest edge we encode. A raw 4K frame is ~8 MB of PNG; 1600px keeps text legible at a fraction. */
const MAX_WIDTH = 1600;

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
 * Capture the screen surface the user picks and return it as a PNG File, or `null` if they declined or
 * dismissed the picker.
 *
 * Only a genuine failure throws. A decline is `NotAllowedError` and is NOT an error condition in this
 * feature — it is the user exercising the choice the permission prompt exists to offer — so it resolves
 * null and the caller simply carries on without an image.
 */
export async function captureScreenshot(): Promise<File | null> {
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

        return await frameToPngFile(stream);
    } catch (error) {
        if (error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
            return null;
        }

        throw error;
    } finally {
        // Stop every track the moment we have our frame. Leaving one running keeps the browser's
        // "sharing your screen" indicator up for the rest of the session, which reads as a bug and is one.
        stream?.getTracks().forEach((track) => track.stop());
    }
}

/** Draw one frame off the live stream and encode it. Split out so the capture flow above stays readable. */
async function frameToPngFile(stream: MediaStream): Promise<File | null> {
    const video = document.createElement('video');
    video.srcObject = stream;
    video.muted = true;

    await video.play();

    // One frame of settle time. Without it `videoWidth` can still be 0 on the first tick and the canvas
    // is drawn empty — the classic silent-blank-screenshot bug.
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

    const sourceWidth = video.videoWidth;
    const sourceHeight = video.videoHeight;

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
    video.pause();
    video.srcObject = null;

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));

    if (blob === null) {
        return null;
    }

    // A File rather than the raw Blob: Inertia's useForm switches to multipart on either, but only a File
    // carries a name, which becomes the attachment's `original_filename` (display metadata only — it never
    // touches the storage key).
    return new File([blob], 'screenshot.png', { type: 'image/png' });
}
