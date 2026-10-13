/**
 * What a guest checking their own paper copy is told about each question (M166, `R-309e4dfa`, `D116`).
 *
 * The facts are `OcrAnswerCarry`'s, the staff review's own; the words are the respondent's. Staff read "needs manual
 * entry" and a confidence figure; the person who wrote the paper needs to know only whether to look again, and that
 * they may simply type the right answer. So there are three kinds of note and no numbers:
 *
 *   - `fill`  — nothing could be filled in: the reader could not make the answer out, or what it read does not fit
 *               this question. The guest is asked to fill it in, and shown what was read when there was something.
 *   - `check` — something was filled in, but the reader was not sure, or read a tick from the ink in its box.
 *   - none    — read with confidence, or left blank on a question that is not a box to tick.
 *
 * A blank TICK question is a `check`: the reader can miss a light tick (`M157`), so "you left this blank" would be a
 * guess. A question the paper did not print says so, so the guest knows why it is empty.
 */
import type { PaperFieldMeta } from './types';

export interface PaperNote {
    kind: 'fill' | 'check';
    text: string;
}

export function paperNote(meta: PaperFieldMeta | undefined): PaperNote | null {
    if (meta === undefined) {
        return null;
    }

    if (meta.state === 'not_on_paper') {
        return { kind: 'fill', text: 'This question is not on your paper copy. Please answer it here.' };
    }

    if (meta.state === 'read' && !meta.carried) {
        return {
            kind: 'fill',
            text: meta.text
                ? `We read “${meta.text}” here, but it does not fit this question. Please fill it in.`
                : 'We could not use what we read here. Please fill it in.',
        };
    }

    if (meta.state === 'unreadable' || meta.state === 'not_found') {
        return { kind: 'fill', text: 'We could not read this answer. Please fill it in.' };
    }

    if (meta.state === 'blank') {
        return meta.ticks ? { kind: 'check', text: 'We did not see a tick here. If you ticked one, please choose it.' } : null;
    }

    if (meta.state === 'read' && (meta.inked || meta.tier === 'review' || meta.tier === 'manual')) {
        return { kind: 'check', text: 'Please check this answer. We may have read it wrong.' };
    }

    return null;
}

/** The notes for a whole form, keyed by question, leaving out every question that needs none. */
export function paperNotes(fields: Record<string, PaperFieldMeta>): Record<string, PaperNote> {
    const notes: Record<string, PaperNote> = {};
    for (const [key, meta] of Object.entries(fields)) {
        const note = paperNote(meta);
        if (note !== null) {
            notes[key] = note;
        }
    }

    return notes;
}
