/**
 * What the review screen knows about a scan (M129 — single-form OCR groundwork 2), and the words a reviewer
 * reads beside each answer. Pure: the encode page and its tests call it with plain data.
 *
 * The server sends the FACTS — state, tier, confidence, the text read, the page — and this module turns them
 * into a note. It holds no list of tier thresholds and no reason sentences: the 90 / 70 bounds live in
 * `config/ocr.php`, which H1d confirmed (ADR-0010), and an answer that could not be filled in is explained once, in
 * the server's own words, in the notice above the form.
 */

/** One question's reading, keyed by the CURRENT version's field key. */
export interface ScanFieldMeta {
    /** `read`, `blank`, `unreadable`, `not_found`, `skipped`, or `not_on_paper` (a question the paper lacked). */
    state: string;
    /** `auto` (90 and above), `review` (70–89) or `manual` (below 70, value withheld); null when nothing was read. */
    tier: string | null;
    confidence: number | null;
    /** The text the reader saw, kept even when its value was withheld. */
    text: string | null;
    page: number | null;
    /** Whether the answer was filled in. False for a value that could not be carried to this question. */
    carried: boolean;
    reason: string | null;
}

export interface ScanPage {
    number: number;
    url: string;
    mime: string;
    /** False while the file waits for its virus check; the image is not served until it passes. */
    servable: boolean;
}

export interface ScanNotice {
    code: string;
    tone: 'info' | 'warning';
    message: string;
    items?: { label: string; text: string | null; message: string }[];
}

/** The `scan` prop of the encode page in scan mode. Its presence IS the mode. */
export interface ScanReview {
    id: string;
    /** Where Save posts the reviewer's answers. */
    submit_url: string;
    /** The answers the reviewer starts from. */
    answers: Record<string, unknown>;
    fields: Record<string, ScanFieldMeta>;
    pages: ScanPage[];
    notices: ScanNotice[];
    version: { number: number; current_number: number };
}

/** `neutral` for a fact, `warning` to check, `danger` to fill in, `info` for context. Never colour alone. */
export type ScanNoteTone = 'neutral' | 'info' | 'warning' | 'danger';

export interface ScanNote {
    tone: ScanNoteTone;
    text: string;
}

/**
 * The note shown with one answer on the review screen, or null when there is nothing to say.
 *
 * ⚠️ A CONFIDENT ANSWER CARRIES NO NOTE. `docs/ocr-pipeline-design.md` §3: flagging every field "would create
 * review fatigue defeating the point of automation", so only what needs a person is marked.
 *
 * @param multiPage  name the page only when there is more than one to look at
 */
export function scanNote(meta: ScanFieldMeta | undefined, multiPage: boolean): ScanNote | null {
    if (meta === undefined) {
        return null;
    }

    const where = multiPage && meta.page !== null ? ` (page ${meta.page})` : '';
    const saw = meta.text !== null && meta.text.trim() !== '' ? `“${meta.text.trim()}”` : null;

    switch (meta.state) {
        case 'not_on_paper':
            return { tone: 'info', text: 'This question was not on the printed paper.' };
        case 'blank':
            return { tone: 'neutral', text: `Left blank on the paper${where}.` };
        case 'not_found':
            return { tone: 'warning', text: 'Not found on the scan. Check the paper and enter it by hand.' };
        case 'unreadable':
            return {
                tone: 'danger',
                text: saw === null
                    ? `Needs manual entry: the scan could not read this answer${where}.`
                    : `Needs manual entry: the scan could not read this answer${where}. It saw ${saw}.`,
            };
        case 'read':
            return readNote(meta, saw, where);
        default:
            return null;
    }
}

function readNote(meta: ScanFieldMeta, saw: string | null, where: string): ScanNote | null {
    if (!meta.carried && meta.reason !== null) {
        return {
            tone: 'warning',
            text: `The scan read ${saw ?? 'an answer'} here${where}, but it could not be filled in. See the list above the form.`,
        };
    }

    if (meta.tier === 'review') {
        const confidence = meta.confidence === null ? '' : ` at ${meta.confidence}% confidence`;
        return { tone: 'warning', text: `Check this answer: it was read${confidence}${where}.` };
    }

    if (meta.tier === 'manual') {
        return {
            tone: 'danger',
            text: saw === null
                ? `Needs manual entry: the scan was not sure enough to fill this in${where}.`
                : `Needs manual entry: the scan read ${saw}${where}, but not clearly enough to fill it in.`,
        };
    }

    return null;
}
