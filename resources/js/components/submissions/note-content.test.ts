import { describe, expect, it } from 'vitest';
import { announcementFor, contentSummary, headingTag, renderableBlocks, safeLink, spanText } from './note-content';

/**
 * M130 (`R-c9f50df2`) — reading a note's stored blocks for display, defensively.
 */
describe('note-content', () => {
    it('places a relative heading under the section heading it sits beneath, never past h6', () => {
        expect(headingTag(2, 1)).toBe('h3');
        expect(headingTag(2, 2)).toBe('h4');
        // The builder preview titles its sections with an h3.
        expect(headingTag(3, 1)).toBe('h4');
        expect(headingTag(5, 2)).toBe('h6');
    });

    it('draws an emptied span as nothing, never as the word null', () => {
        expect(spanText({ text: null })).toBe('');
        expect(spanText({ text: 'Bring your card' })).toBe('Bring your card');
    });

    it('keeps a link only when it is safe NOW, whatever storage says', () => {
        expect(safeLink({ text: 'a', link: 'https://example.org/help' })).toBe('https://example.org/help');
        expect(safeLink({ text: 'a', link: 'mailto:clinic@example.org' })).toBe('mailto:clinic@example.org');
        for (const unsafe of ['javascript:alert(1)', 'java\nscript:alert(1)', 'data:text/html,x', '', ' https://example.org']) {
            expect(safeLink({ text: 'a', link: unsafe }), JSON.stringify(unsafe)).toBeNull();
        }
        expect(safeLink({ text: 'a' })).toBeNull();
    });

    it('reads every block of the closed shape, and lenient drafts without guessing', () => {
        const blocks = renderableBlocks([
            { type: 'heading', level: 2, text: 'Before you begin' },
            { type: 'heading', level: 9, text: null },
            { type: 'paragraph', spans: [{ text: 'Read ' }, { text: 'this', bold: true }, 'not a span'] },
            { type: 'callout', tone: 'warning', spans: [] },
            { type: 'callout', tone: 'neon', spans: [{ text: 'x' }] },
            { type: 'divider' },
            { type: 'image', attachment_id: 'att-1', alt: null },
            { type: 'image', attachment_id: '' },
            { type: 'video', src: 'x' },
            'a string',
            null,
        ]);

        expect(blocks).toEqual([
            { type: 'heading', level: 2, text: 'Before you begin' },
            { type: 'heading', level: 1, text: null },
            { type: 'paragraph', spans: [{ text: 'Read ' }, { text: 'this', bold: true }] },
            { type: 'callout', tone: 'warning', spans: [] },
            { type: 'callout', tone: 'info', spans: [{ text: 'x' }] },
            { type: 'divider' },
            { type: 'image', attachment_id: 'att-1', alt: null },
        ]);
        expect(renderableBlocks(undefined)).toEqual([]);
        expect(renderableBlocks({ type: 'divider' })).toEqual([]);
    });

    it('summarises a note by its first text, then its first image description, then nothing', () => {
        expect(contentSummary(renderableBlocks([{ type: 'divider' }, { type: 'paragraph', spans: [{ text: 'Fasting ' }, { text: 'required', bold: true }] }]))).toBe(
            'Fasting required',
        );
        expect(contentSummary(renderableBlocks([{ type: 'heading', level: 1, text: '  ' }, { type: 'image', attachment_id: 'a', alt: 'A map of the entrance' }]))).toBe(
            'A map of the entrance',
        );
        expect(contentSummary(renderableBlocks([{ type: 'divider' }, { type: 'image', attachment_id: 'a', alt: null }]))).toBeNull();
    });

    it('announces a note with content by what it says, and never by its author-only label (D69)', () => {
        const blocks = [{ type: 'paragraph', spans: [{ text: 'Bring your card.' }] }];

        expect(announcementFor('note', blocks, 'Fasting note (for the team)')).toBe('New information: Bring your card.');
        // Blocks that say nothing announce nothing, rather than an empty "New information: ".
        expect(announcementFor('note', [{ type: 'divider' }], 'Fasting note (for the team)')).toBeNull();
        // A note with no content still shows, and so announces, its label — as every other question does.
        expect(announcementFor('note', null, 'Welcome to the clinic.')).toBe('New question: Welcome to the clinic.');
        expect(announcementFor('short_text', blocks, 'Full name')).toBe('New question: Full name');
    });
});
