import { describe, expect, it } from 'vitest';
import { scanNote, type ScanFieldMeta } from './scan-review';

/**
 * M129 — the words beside each answer on the review screen. Pure: facts in, a note out, and a confident answer
 * gets none (`docs/ocr-pipeline-design.md` §3: flagging everything defeats the point of reading it).
 */

function meta(overrides: Partial<ScanFieldMeta>): ScanFieldMeta {
    return { state: 'read', tier: 'auto', confidence: 96, text: 'Maria', page: 1, carried: true, reason: null, ...overrides };
}

describe('scanNote', () => {
    it('says nothing about a confident answer, or about a question the scan never touched', () => {
        expect(scanNote(meta({}), false)).toBeNull();
        expect(scanNote(undefined, false)).toBeNull();
        expect(scanNote(meta({ state: 'skipped', tier: null }), false)).toBeNull();
    });

    it('asks for a check, with the confidence, when the answer was filled in below the auto threshold', () => {
        expect(scanNote(meta({ tier: 'review', confidence: 82 }), false)).toEqual({
            tone: 'warning',
            text: 'Check this answer: it was read at 82% confidence.',
        });
    });

    it('fills a low-confidence answer in and says it may be wrong, with the confidence (D108)', () => {
        expect(scanNote(meta({ tier: 'manual', confidence: 52, text: 'fever?' }), false)).toEqual({
            tone: 'danger',
            text: 'This may be wrong: it was read at 52% confidence. Check it against the paper.',
        });
        expect(scanNote(meta({ tier: 'manual', confidence: 41, page: 2 }), true)?.text).toBe(
            'This may be wrong: it was read at 41% confidence (page 2). Check it against the paper.',
        );
    });

    it('asks for manual entry, quoting what was seen, when a scan read before D108 withheld the value', () => {
        expect(scanNote(meta({ tier: 'manual', text: 'fever?', carried: false }), false)).toEqual({
            tone: 'danger',
            text: 'Needs manual entry: the scan read “fever?”, but not clearly enough to fill it in.',
        });
        expect(scanNote(meta({ tier: 'manual', text: null, carried: false }), false)?.text).toBe(
            'Needs manual entry: the scan was not sure enough to fill this in.',
        );
    });

    it('treats an unreadable answer as manual entry too', () => {
        expect(scanNote(meta({ state: 'unreadable', tier: null, text: 'scribble' }), false)).toEqual({
            tone: 'danger',
            text: 'Needs manual entry: the scan could not read this answer. It saw “scribble”.',
        });
    });

    it('states a blank, a missing question and a question the paper never had, each in its own tone', () => {
        expect(scanNote(meta({ state: 'blank', tier: null, text: null }), false)).toEqual({ tone: 'neutral', text: 'Left blank on the paper.' });
        expect(scanNote(meta({ state: 'not_found', tier: null }), false)?.tone).toBe('warning');
        expect(scanNote(meta({ state: 'not_on_paper', tier: null }), false)).toEqual({
            tone: 'info',
            text: 'This question was not on the printed paper.',
        });
    });

    it('points at the list above the form for an answer that could not be filled in', () => {
        expect(scanNote(meta({ carried: false, reason: 'type_changed', text: '41' }), false)?.text).toBe(
            'The scan read “41” here, but it could not be filled in. See the list above the form.',
        );
    });

    it('names the page only when there is more than one to look at', () => {
        const review = meta({ tier: 'review', confidence: 75, page: 2 });

        expect(scanNote(review, true)?.text).toBe('Check this answer: it was read at 75% confidence (page 2).');
        expect(scanNote(review, false)?.text).toBe('Check this answer: it was read at 75% confidence.');
    });
});
