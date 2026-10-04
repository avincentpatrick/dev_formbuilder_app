/**
 * The editing syntax for a content block's text (M129 — `R-6dedc3a9`'s editor half), and its round trip.
 *
 * The STORED form is the closed span list `App\Rules\ContentBlocks` defines: no HTML string, no markup language,
 * every leaf a plain string. An author still needs bold, italic and links, so the textarea speaks a small
 * syntax that exists only in the editor and is parsed into spans before anything is saved:
 *
 *   **bold**   __italic__   `code`   [link text](https://…)   and a backslash before * _ ` [ ] ( ) \ for the
 *   character itself. A delimiter with no partner is just text.
 *
 * Marks nest in one canonical order, link ⊃ bold ⊃ italic ⊃ code, which is the order the serializer writes and
 * the only order the parser opens.
 *
 * ── THE ROUND TRIP IS IDENTITY, BY CONSTRUCTION ───────────────────────────────────────────────────────
 * `parseMarkup(serializeSpans(S))` equals `normalizeSpans(S)` for every span list. The serializer first writes
 * the minimal form — no escapes at all, so a typed `5 * 3` or `snake_case` reloads exactly as typed — and keeps
 * it only if parsing it back gives the same spans. Otherwise it writes the safe form, which escapes every special
 * character in text, and whose correctness does not depend on the text at all. The test drives both with
 * hundreds of seeded random lists.
 *
 * ── NORMAL FORM ─────────────────────────────────────────────────────────────────────────────────────────
 * Empty text is dropped; an off flag is absent, never false; a cleared link is deleted, never null or '' (the
 * save would accept it and publish would refuse it); and adjacent runs with the same marks merge — up to the
 * server's per-span length, beyond which the run continues in a second span with the same marks, so a long
 * paragraph is never refused for being one span.
 */

import { CONTENT_LIMITS, LINK_SCHEMES, type ContentSpan } from './content-blocks';

/** The characters a backslash escapes. Before anything else, a backslash is just a backslash. */
const SPECIALS = new Set(['\\', '*', '_', '`', '[', ']', '(', ')']);

interface Marks {
    bold: boolean;
    italic: boolean;
    code: boolean;
    link: string | null;
}

const NO_MARKS: Marks = { bold: false, italic: false, code: false, link: null };

function spanOf(text: string, marks: Marks): ContentSpan {
    const span: ContentSpan = { text };
    if (marks.bold) {
        span.bold = true;
    }
    if (marks.italic) {
        span.italic = true;
    }
    if (marks.code) {
        span.code = true;
    }
    if (marks.link !== null && marks.link !== '') {
        span.link = marks.link;
    }
    return span;
}

function marksOf(span: ContentSpan): Marks {
    return {
        bold: span.bold === true,
        italic: span.italic === true,
        code: span.code === true,
        link: typeof span.link === 'string' && span.link !== '' ? span.link : null,
    };
}

function sameMarks(a: Marks, b: Marks): boolean {
    return a.bold === b.bold && a.italic === b.italic && a.code === b.code && a.link === b.link;
}

/** Length in code points — what the server's `mb_strlen` counts, and never a split through a surrogate pair. */
function codePoints(text: string): string[] {
    return Array.from(text);
}

/** The normal form (see the header): no empty runs, no off flags, no cleared links, same-mark runs merged. */
export function normalizeSpans(spans: ContentSpan[]): ContentSpan[] {
    const out: ContentSpan[] = [];
    const cap = CONTENT_LIMITS.maxTextLength;

    for (const raw of spans) {
        const text = raw.text ?? '';
        if (text === '') {
            continue;
        }
        const marks = marksOf(raw);
        let rest = codePoints(text);

        while (rest.length > 0) {
            const last = out[out.length - 1];
            const room = last !== undefined && sameMarks(marksOf(last), marks) ? cap - codePoints(last.text ?? '').length : 0;

            if (room > 0) {
                last!.text = (last!.text ?? '') + rest.slice(0, room).join('');
                rest = rest.slice(room);
                continue;
            }

            out.push(spanOf(rest.slice(0, cap).join(''), marks));
            rest = rest.slice(cap);
        }
    }

    return out;
}

/** Parse the editing syntax into the stored span shape, in normal form. */
export function parseMarkup(input: string): ContentSpan[] {
    const spans: ContentSpan[] = [];
    parseInto(input, NO_MARKS, spans);
    return normalizeSpans(spans);
}

function parseInto(s: string, marks: Marks, out: ContentSpan[]): void {
    let buffer = '';
    const flush = (): void => {
        if (buffer !== '') {
            out.push(spanOf(buffer, marks));
            buffer = '';
        }
    };

    let i = 0;
    while (i < s.length) {
        const c = s[i];

        if (c === '\\' && i + 1 < s.length && SPECIALS.has(s[i + 1])) {
            buffer += s[i + 1];
            i += 2;
            continue;
        }

        if (c === '`' && !marks.code) {
            const close = findClose(s, i + 1, '`', true);
            if (close > i + 1) {
                flush();
                out.push(spanOf(unescape(s.slice(i + 1, close)), { ...marks, code: true }));
                i = close + 1;
                continue;
            }
        }

        if (s.startsWith('**', i) && !marks.bold && !marks.italic && !marks.code) {
            const close = findClose(s, i + 2, '**', false);
            if (close > i + 2) {
                flush();
                parseInto(s.slice(i + 2, close), { ...marks, bold: true }, out);
                i = close + 2;
                continue;
            }
        }

        if (s.startsWith('__', i) && !marks.italic && !marks.code) {
            const close = findClose(s, i + 2, '__', false);
            if (close > i + 2) {
                flush();
                parseInto(s.slice(i + 2, close), { ...marks, italic: true }, out);
                i = close + 2;
                continue;
            }
        }

        if (c === '[' && marks.link === null && !marks.bold && !marks.italic && !marks.code) {
            const link = matchLink(s, i);
            if (link !== null) {
                flush();
                parseInto(link.text, { ...marks, link: link.url }, out);
                i = link.end;
                continue;
            }
        }

        buffer += c;
        i += 1;
    }

    flush();
}

/**
 * The index of the next unescaped `delimiter` from `start`, or -1. Outside code, a whole code span is skipped,
 * so a delimiter inside backticks never closes anything around them.
 */
function findClose(s: string, start: number, delimiter: string, inCode: boolean): number {
    let i = start;
    while (i < s.length) {
        if (s[i] === '\\' && i + 1 < s.length && SPECIALS.has(s[i + 1])) {
            i += 2;
            continue;
        }
        if (s.startsWith(delimiter, i)) {
            return i;
        }
        if (!inCode && s[i] === '`') {
            const close = findClose(s, i + 1, '`', true);
            if (close > i + 1) {
                i = close + 1;
                continue;
            }
        }
        i += 1;
    }
    return -1;
}

/** `[text](url)` starting at `start`, or null when the brackets do not make a link. */
function matchLink(s: string, start: number): { text: string; url: string; end: number } | null {
    const close = findClose(s, start + 1, ']', false);
    if (close <= start + 1 || s[close + 1] !== '(') {
        return null;
    }

    let i = close + 2;
    let url = '';
    while (i < s.length) {
        if (s[i] === '\\' && i + 1 < s.length && SPECIALS.has(s[i + 1])) {
            url += s[i + 1];
            i += 2;
            continue;
        }
        if (s[i] === ')') {
            return url === '' ? null : { text: s.slice(start + 1, close), url, end: i + 1 };
        }
        url += s[i];
        i += 1;
    }

    return null;
}

function unescape(s: string): string {
    let out = '';
    for (let i = 0; i < s.length; i += 1) {
        if (s[i] === '\\' && i + 1 < s.length && SPECIALS.has(s[i + 1])) {
            out += s[i + 1];
            i += 1;
            continue;
        }
        out += s[i];
    }
    return out;
}

type Encoder = (text: string) => string;

const AS_IS: Encoder = (text) => text;
const ESCAPE_TEXT: Encoder = (text) => text.replace(/[\\*_`[\]()]/g, (character) => `\\${character}`);
const ESCAPE_CODE: Encoder = (text) => text.replace(/[\\`]/g, (character) => `\\${character}`);
const ESCAPE_URL: Encoder = (text) => text.replace(/[\\()]/g, (character) => `\\${character}`);

function write(span: ContentSpan, text: Encoder, code: Encoder, url: Encoder): string {
    const body = span.text ?? '';
    let out = span.code === true ? `\`${code(body)}\`` : text(body);
    if (span.italic === true) {
        out = `__${out}__`;
    }
    if (span.bold === true) {
        out = `**${out}**`;
    }
    if (typeof span.link === 'string' && span.link !== '') {
        out = `[${out}](${url(span.link)})`;
    }
    return out;
}

/** Serialize spans into the editing syntax: the minimal form when it parses back exactly, the safe form if not. */
export function serializeSpans(spans: ContentSpan[]): string {
    const normal = normalizeSpans(spans);
    const minimal = normal.map((span) => write(span, AS_IS, AS_IS, AS_IS)).join('');

    if (JSON.stringify(parseMarkup(minimal)) === JSON.stringify(normal)) {
        return minimal;
    }

    return normal.map((span) => write(span, ESCAPE_TEXT, ESCAPE_CODE, ESCAPE_URL)).join('');
}

/** Length in UTF-8 bytes — what PHP's `strlen` counts, where `.length` counts UTF-16 units. */
function byteLength(text: string): number {
    return new TextEncoder().encode(text).length;
}

/**
 * The TS twin of `ContentBlocks::linkIsSafe()`, so the editor can refuse a link before a save is refused for it, and
 * — since M130 — so `NoteContent.vue` re-checks every link at render. Both read `tests/fixtures/content-block-links.json`,
 * and each suite fails if its half disagrees with a vector, the drift test `R-c9f50df2` asked for.
 *
 * ⚠️ The host check is written out rather than left to `new URL()`, because the WHATWG parser repairs input
 * PHP's `parse_url()` refuses (`http:/x` gains a host), and the two halves must refuse the same links. What follows
 * is `parse_url()`'s own reading of an authority (`ext/standard/url.c`), step for step: the host starts after the
 * LAST `@`; a bracketed IPv6 host skips the port scan; otherwise the LAST `:` starts a port, which must be at most
 * five bytes and read as a number from 0 to 65535 the way `strtol` reads one; and what is left must not be empty.
 * Measured before it was written: the first version took the FIRST `@` and stripped any digits after a colon, so it
 * passed `https://a@b@/` and `https://host:99999/`, both of which PHP refuses — a save refused for a link the editor
 * had called safe.
 */
export function linkLooksSafe(link: string): boolean {
    if (byteLength(link) > CONTENT_LIMITS.maxLinkLength) {
        return false;
    }
    // No whitespace or control character anywhere — browsers strip them before reading a scheme. Checked by code
    // point rather than by a character-class escape, which this repository's tooling has rewritten in transit.
    for (const character of link) {
        const code = character.codePointAt(0) ?? 0;
        if (code <= 0x20 || code === 0x7f) {
            return false;
        }
    }

    const match = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(link);
    if (match === null) {
        return false;
    }

    const scheme = match[1].toLowerCase();
    if (!LINK_SCHEMES.includes(scheme)) {
        return false;
    }
    if (scheme !== 'http' && scheme !== 'https') {
        return true;
    }

    const rest = link.slice(match[0].length);
    if (!rest.startsWith('//')) {
        return false;
    }
    const authority = rest.slice(2).split(/[/?#]/)[0];
    const hostAndPort = authority.slice(authority.lastIndexOf('@') + 1);
    if (hostAndPort.startsWith('[') && hostAndPort.endsWith(']')) {
        return true;
    }

    const colon = hostAndPort.lastIndexOf(':');
    if (colon === -1) {
        return hostAndPort !== '';
    }

    const port = hostAndPort.slice(colon + 1);
    if (byteLength(port) > 5) {
        return false;
    }
    if (port !== '') {
        // `strtol`: an optional sign, then digits, stopping at the first character that is not one.
        const digits = /^[+-]?[0-9]+/.exec(port);
        const value = digits === null ? -1 : Number(digits[0]);
        if (!(value >= 0 && value <= 65535)) {
            return false;
        }
    }

    return colon > 0;
}
