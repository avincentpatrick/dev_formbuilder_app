import { describe, expect, it } from 'vitest';
import { paperNote, paperNotes } from '../lib/paper-notes';
import type { PaperFieldMeta } from '../lib/types';

/**
 * M166 (`D116`) — what the sender of a paper copy is told about each question. Three kinds and no numbers: fill it in,
 * check it, or nothing. A confident read and a blank write-in say nothing; a blank tick question is a `check`, because
 * the reader can miss a light tick (`M157`).
 */

function meta(overrides: Partial<PaperFieldMeta>): PaperFieldMeta {
    return { state: 'read', tier: 'auto', confidence: 95, text: 'x', page: 1, carried: true, reason: null, ticks: false, inked: false, ...overrides };
}

describe('paperNote', () => {
    it('says nothing about a confident read, a blank write-in, a skipped question or one it has no facts for', () => {
        expect(paperNote(meta({}))).toBeNull();
        expect(paperNote(meta({ state: 'blank', tier: null, carried: false }))).toBeNull();
        expect(paperNote(meta({ state: 'skipped', tier: null, carried: false }))).toBeNull();
        expect(paperNote(undefined)).toBeNull();
    });

    it('asks to check an unsure read, one read from the ink, and a tick question left blank', () => {
        expect(paperNote(meta({ tier: 'review' }))?.kind).toBe('check');
        expect(paperNote(meta({ tier: 'manual' }))?.kind).toBe('check');
        expect(paperNote(meta({ inked: true }))?.kind).toBe('check');
        expect(paperNote(meta({ state: 'blank', tier: null, carried: false, ticks: true }))).toEqual({
            kind: 'check',
            text: 'We did not see a tick here. If you ticked one, please choose it.',
        });
    });

    it('asks to fill in what could not be read or used, quoting what was read, and a question the paper lacked', () => {
        expect(paperNote(meta({ state: 'unreadable', tier: null, carried: false }))?.text).toBe('We could not read this answer. Please fill it in.');
        expect(paperNote(meta({ state: 'not_found', tier: null, carried: false }))?.kind).toBe('fill');
        expect(paperNote(meta({ carried: false, reason: 'option_missing', text: 'Maybe' }))?.text).toBe(
            'We read “Maybe” here, but it does not fit this question. Please fill it in.',
        );
        expect(paperNote(meta({ carried: false, reason: 'type_changed', text: null }))?.text).toBe('We could not use what we read here. Please fill it in.');
        expect(paperNote(meta({ state: 'not_on_paper', tier: null, carried: false }))?.kind).toBe('fill');
    });

    it('keys a form\'s notes by question and leaves out every question that needs none', () => {
        expect(Object.keys(paperNotes({ a: meta({}), b: meta({ tier: 'review' }), c: meta({ state: 'unreadable', carried: false }) }))).toEqual(['b', 'c']);
    });
});
