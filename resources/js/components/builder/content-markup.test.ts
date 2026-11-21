/**
 * The content editor's text syntax (M129, `R-6dedc3a9`'s editor half): its round trip, its normal form, and the link
 * check it shares with the server.
 *
 * ⛔ THE ROUND TRIP IS A PROPERTY, SO IT IS TESTED AS ONE. Hand-picked vectors only ever cover the cases somebody
 * thought of; the serializer's whole claim is "for EVERY span list", so hundreds of lists are drawn from a seeded
 * generator over an alphabet that is mostly the syntax's own special characters. The seed is fixed, so a failure
 * names a reproducible case rather than a flake.
 *
 * ⛔ THE LINK VECTORS ARE READ FROM THE FILE THE PHP SUITE READS (`tests/fixtures/content-block-links.json`). A link
 * this half calls safe and `ContentBlocks::linkIsSafe()` refuses would cost the author the whole save, so a
 * disagreement on any vector fails here, and the PHP twin fails on the same file.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { CONTENT_LIMITS, type ContentSpan } from './content-blocks';
import { linkLooksSafe, normalizeSpans, parseMarkup, serializeSpans } from './content-markup';

/** mulberry32: a small seeded generator, so every run draws the same lists. */
function seeded(seed: number): () => number {
    let state = seed >>> 0;
    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// Mostly the syntax's own characters, so nearly every list needs escaping somewhere; plus a space, a newline, a
// two-unit emoji and a letter with an accent, because the parser walks UTF-16 units and the cap counts code points.
const ALPHABET = ['a', 'b', ' ', '*', '**', '_', '__', '`', '[', ']', '(', ')', '\\', '\n', 'é', '😀', 'x'];
const LINKS = ['https://example.org', 'https://example.org/a_(b)*c', 'mailto:help@example.org', 'tel:+639171234567', 'http://e.org/\\x)'];

function randomText(next: () => number): string {
    let text = '';
    const length = Math.floor(next() * 8);
    for (let i = 0; i < length; i += 1) {
        text += ALPHABET[Math.floor(next() * ALPHABET.length)];
    }
    return text;
}

// M245: glossary words, each a valid term (no edge space, line break or bar) built from the syntax's own characters.
const TERMS = ['TWG', 'B07', 'a*b', 'x]y', 'é😀', 'un_der', '[[nested', 'two words', 'C:' + String.fromCharCode(92) + 'path'];

function randomSpans(next: () => number): ContentSpan[] {
    const spans: ContentSpan[] = [];
    const count = Math.floor(next() * 6);
    for (let i = 0; i < count; i += 1) {
        if (next() < 0.2) {
            const term = TERMS[Math.floor(next() * TERMS.length)];
            const span: ContentSpan = { text: term, term };
            if (next() < 0.35) span.bold = true;
            if (next() < 0.35) span.italic = true;
            spans.push(span);
            continue;
        }
        const span: ContentSpan = { text: randomText(next) };
        if (next() < 0.35) span.bold = true;
        if (next() < 0.35) span.italic = true;
        if (next() < 0.2) span.code = true;
        if (next() < 0.25) span.link = LINKS[Math.floor(next() * LINKS.length)];
        spans.push(span);
    }
    return spans;
}

describe('the round trip', () => {
    it('gives back the normal form of every one of 600 seeded span lists', () => {
        const next = seeded(129);
        for (let run = 0; run < 600; run += 1) {
            const spans = randomSpans(next);
            const text = serializeSpans(spans);
            expect(parseMarkup(text), `run ${run}: ${JSON.stringify(spans)} wrote ${JSON.stringify(text)}`).toEqual(normalizeSpans(spans));
        }
    });

    it('reads anything typed into a normal form that survives its own round trip', () => {
        const next = seeded(4129);
        for (let run = 0; run < 400; run += 1) {
            const typed = randomText(next) + randomText(next) + randomText(next);
            const spans = parseMarkup(typed);
            expect(normalizeSpans(spans), `typed ${JSON.stringify(typed)}`).toEqual(spans);
            expect(parseMarkup(serializeSpans(spans)), `typed ${JSON.stringify(typed)}`).toEqual(spans);
        }
    });

    it('writes the same text again from what it read back', () => {
        const next = seeded(29);
        for (let run = 0; run < 200; run += 1) {
            const text = serializeSpans(randomSpans(next));
            expect(serializeSpans(parseMarkup(text))).toBe(text);
        }
    });
});

describe('what an author types', () => {
    it.each([
        ['5 * 3 = 15', [{ text: '5 * 3 = 15' }]],
        ['snake_case and a_b', [{ text: 'snake_case and a_b' }]],
        ['an unmatched ** stays', [{ text: 'an unmatched ** stays' }]],
        ['**bold** then __italic__', [{ text: 'bold', bold: true }, { text: ' then ' }, { text: 'italic', italic: true }]],
        ['`2*3` is code', [{ text: '2*3', code: true }, { text: ' is code' }]],
        ['[**Read**](https://example.org)', [{ text: 'Read', bold: true, link: 'https://example.org' }]],
        ['[a](https://e.org/x\\)y)', [{ text: 'a', link: 'https://e.org/x)y' }]],
        ['a \\* star', [{ text: 'a * star' }]],
        ['[no address]()', [{ text: '[no address]()' }]],
        // M245: glossary words.
        ['ask the [[TWG]] first', [{ text: 'ask the ' }, { text: 'TWG', term: 'TWG' }, { text: ' first' }]],
        ['**[[TWG]]** and __[[B07]]__', [{ text: 'TWG', bold: true, term: 'TWG' }, { text: ' and ' }, { text: 'B07', italic: true, term: 'B07' }]],
        ['[[TWG]][[TWG]]', [{ text: 'TWG', term: 'TWG' }, { text: 'TWG', term: 'TWG' }]],
        ['[[ TWG ]] has spaces', [{ text: '[[ TWG ]] has spaces' }]],
        ['[[]] is empty', [{ text: '[[]] is empty' }]],
        ['[[a|b]] has a bar', [{ text: '[[a|b]] has a bar' }]],
        ['`[[TWG]]` is code', [{ text: '[[TWG]]', code: true }, { text: ' is code' }]],
        ['[[unclosed', [{ text: '[[unclosed' }]],
    ])('reads %j', (typed, spans) => {
        expect(parseMarkup(typed)).toEqual(spans);
    });

    it('writes plain text back exactly as it was typed when nothing needs escaping', () => {
        for (const typed of ['5 * 3 = 15', 'snake_case and a_b', 'an unmatched ** stays', 'C:\\path']) {
            expect(serializeSpans(parseMarkup(typed))).toBe(typed);
        }
    });

    it('escapes what would otherwise read back as formatting', () => {
        const spans: ContentSpan[] = [{ text: '**not bold**' }];
        const text = serializeSpans(spans);

        expect(text).not.toBe('**not bold**');
        expect(parseMarkup(text)).toEqual(spans);
    });

    it('never merges a glossary word with the text beside it, and escapes brackets that are only text', () => {
        expect(normalizeSpans([{ text: 'the ' }, { text: 'TWG', term: 'TWG' }, { text: 'TWG', term: 'TWG' }])).toEqual([
            { text: 'the ' },
            { text: 'TWG', term: 'TWG' },
            { text: 'TWG', term: 'TWG' },
        ]);

        const literal: ContentSpan[] = [{ text: 'type [[TWG]] to mark it' }];
        expect(serializeSpans(literal)).not.toBe('type [[TWG]] to mark it');
        expect(parseMarkup(serializeSpans(literal))).toEqual(literal);
    });

    it('keeps a closing bracket inside an address, by escaping it', () => {
        const spans: ContentSpan[] = [{ text: 'see', link: 'https://example.org/a_(b)' }];

        expect(parseMarkup(serializeSpans(spans))).toEqual(spans);
    });
});

describe('the normal form', () => {
    it('drops empty text, off flags and cleared links — leaving no key behind', () => {
        const spans = [
            { text: '' },
            { text: 'a', bold: false, italic: false, code: false, link: '' },
            { text: 'b', link: null as unknown as string },
        ] as ContentSpan[];

        const normal = normalizeSpans(spans);

        expect(normal).toEqual([{ text: 'ab' }]);
        expect(Object.keys(normal[0])).toEqual(['text']);
    });

    it('splits a run longer than the server allows into pieces with the same marks', () => {
        const long = 'é'.repeat(CONTENT_LIMITS.maxTextLength + 500);
        const normal = normalizeSpans([{ text: long, bold: true }]);

        expect(normal.map((span) => Array.from(span.text ?? '').length)).toEqual([CONTENT_LIMITS.maxTextLength, 500]);
        expect(normal.every((span) => span.bold === true)).toBe(true);
        expect(parseMarkup(serializeSpans(normal))).toEqual(normal);
    });

    it('never splits a character that takes two UTF-16 units', () => {
        const normal = normalizeSpans([{ text: 'a' + '😀'.repeat(CONTENT_LIMITS.maxTextLength) }]);

        expect(normal.map((span) => Array.from(span.text ?? '').length)).toEqual([CONTENT_LIMITS.maxTextLength, 1]);
        expect(normal[1].text).toBe('😀');
    });
});

describe('the link check it shares with the server', () => {
    const vectors = JSON.parse(readFileSync(join(process.cwd(), 'tests', 'fixtures', 'content-block-links.json'), 'utf-8')) as {
        safe: string[];
        unsafe: string[];
    };

    it('reads both halves of the shared fixture, so neither can pass by being empty', () => {
        expect(vectors.safe).toHaveLength(13);
        expect(vectors.unsafe).toHaveLength(23);
    });

    it.each(vectors.safe.map((link, i) => [i + 1, link]))('calls safe vector #%i safe', (_, link) => {
        expect(linkLooksSafe(link as string)).toBe(true);
    });

    it.each(vectors.unsafe.map((link, i) => [i + 1, link]))('calls unsafe vector #%i unsafe', (_, link) => {
        expect(linkLooksSafe(link as string)).toBe(false);
    });
});
