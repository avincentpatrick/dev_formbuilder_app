/**
 * M187 (`R-af2d278b`) — the drawing behind {@link SignaturePad.vue}, kept apart from the component so the rules can be
 * read and tested without a canvas (happy-dom draws nothing).
 *
 * A point is stored as a fraction of the pad's width and height, so a pad that changes size — a phone turned on its
 * side — redraws the same signature at the new size instead of losing it, and the saved picture is drawn from the same
 * strokes at a fixed size whatever the screen was.
 */

export interface PadPoint {
    x: number;
    y: number;
}

export type PadStroke = PadPoint[];

/** How long the pen stays up before the drawing is saved: long enough for the next stroke of the same signature. */
export const SETTLE_MS = 800;

/** The saved picture, in pixels: 3:1 like the pad, on white, so it prints and embeds in the PDF as it was drawn. */
export const EXPORT_WIDTH = 900;
export const EXPORT_HEIGHT = 300;

/** The pad's height is a third of its width, so a vertical fraction counts a third as much as a horizontal one. */
const ASPECT = EXPORT_HEIGHT / EXPORT_WIDTH;

/** Below this much ink (in pad widths) the strokes are a tap or a dot, not a signature. */
const MIN_INK = 0.02;

const INK = '#111111';
const PAPER = '#ffffff';

function clamp(value: number): number {
    return Math.min(1, Math.max(0, value));
}

/** Where a pointer is, as a fraction of the pad; a stroke that runs off the pad stays on its edge. */
export function toPadPoint(clientX: number, clientY: number, rect: { left: number; top: number; width: number; height: number }): PadPoint {
    const width = rect.width > 0 ? rect.width : 1;
    const height = rect.height > 0 ? rect.height : 1;

    return { x: clamp((clientX - rect.left) / width), y: clamp((clientY - rect.top) / height) };
}

/** The length of every stroke together, in pad widths. */
export function inkLength(strokes: readonly PadStroke[]): number {
    let length = 0;
    for (const stroke of strokes) {
        for (let i = 1; i < stroke.length; i += 1) {
            length += Math.hypot(stroke[i].x - stroke[i - 1].x, (stroke[i].y - stroke[i - 1].y) * ASPECT);
        }
    }

    return length;
}

/** Whether the strokes amount to a signature at all — a tap or a dot does not, and is never saved. */
export function hasInk(strokes: readonly PadStroke[]): boolean {
    return inkLength(strokes) >= MIN_INK;
}

/** Paint the strokes on white, filling a canvas of `width` × `height` device pixels. */
export function drawStrokes(ctx: CanvasRenderingContext2D, strokes: readonly PadStroke[], width: number, height: number): void {
    ctx.fillStyle = PAPER;
    ctx.fillRect(0, 0, width, height);
    ctx.strokeStyle = INK;
    ctx.fillStyle = INK;
    ctx.lineWidth = Math.max(1.5, width / 300);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    for (const stroke of strokes) {
        if (stroke.length === 0) {
            continue;
        }
        if (stroke.length === 1) {
            ctx.beginPath();
            ctx.arc(stroke[0].x * width, stroke[0].y * height, ctx.lineWidth / 2, 0, Math.PI * 2);
            ctx.fill();
            continue;
        }
        ctx.beginPath();
        ctx.moveTo(stroke[0].x * width, stroke[0].y * height);
        for (let i = 1; i < stroke.length; i += 1) {
            ctx.lineTo(stroke[i].x * width, stroke[i].y * height);
        }
        ctx.stroke();
    }
}

/** The saved picture: the strokes redrawn at {@link EXPORT_WIDTH} × {@link EXPORT_HEIGHT} on white, as a PNG. */
export function exportPng(strokes: readonly PadStroke[]): Promise<Blob> {
    return new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');
        canvas.width = EXPORT_WIDTH;
        canvas.height = EXPORT_HEIGHT;
        const ctx = canvas.getContext('2d');
        if (ctx === null) {
            reject(new Error('This browser cannot save a drawing.'));
            return;
        }
        drawStrokes(ctx, strokes, EXPORT_WIDTH, EXPORT_HEIGHT);
        canvas.toBlob((blob) => (blob === null ? reject(new Error('The signature could not be saved.')) : resolve(blob)), 'image/png');
    });
}
