import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { manual, manualLinks, quotedLabels } from './manual';
import { navItems } from '@/components/shell/nav-model';

/**
 * The user manual's labels, checked against the files that draw them (M162, `R-f9211765`, `D114`).
 *
 * ⛔ WHAT THIS FILE IS FOR. The manual tells a first-time user "click **Copy link**". If a later change renames that
 * button, nothing else in the repository notices: the manual is data, its page renders whatever it holds, and every
 * other test is about the button rather than about the sentence naming it. The reader is then sent looking for a
 * control that is not there — worse than no manual, because they trust it. So each bold label carries the file that
 * draws it, and this test reads that file.
 *
 * ⚠️ IT PROVES A LABEL EXISTS, NOT THAT THE STEP AROUND IT IS RIGHT. A label can survive while the button moves to
 * another tab; walking the manual in a browser is the check for that, and no test here replaces it.
 *
 * The node container mounts the whole repository, so `app/**` (where three of the labels live, in PHP) is readable
 * from here — measured before this was written, and the reason this is a Vitest case rather than a Pest one: the
 * manual is TypeScript, and Pest would have to parse it as text.
 */

const ROOTS = ['resources/js/', 'app/', 'packages/design-system/src/'];

const sources = new Map<string, string | null>();

/** A file's text with every run of whitespace collapsed, so a label wrapped across template lines still matches. */
function source(path: string): string | null {
    if (!sources.has(path)) {
        const full = join(process.cwd(), path);
        sources.set(path, existsSync(full) ? readFileSync(full, 'utf8').replace(/\s+/g, ' ') : null);
    }
    return sources.get(path) ?? null;
}

function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * The label as a whole word or phrase: no letter or digit may touch either end, so "All" is not found inside
 * "Allow" and "Draft" is not found inside "Drafts". Whitespace inside the label matches any run of it.
 */
function containsLabel(text: string, label: string): boolean {
    const body = label.split(/\s+/).map(escapeRegExp).join('\\s+');
    return new RegExp(`(?<![\\p{L}\\p{N}])${body}(?![\\p{L}\\p{N}])`, 'u').test(text);
}

describe('the manual quotes only labels that are on screen', () => {
    const labels = quotedLabels();

    it('quotes enough labels from enough files to be worth checking', () => {
        // Anti-vacuity: a refactor of `quotedLabels()` that walked nothing would pass every case below.
        expect(labels.length).toBeGreaterThanOrEqual(40);
        expect(new Set(labels.map((l) => l.from)).size).toBeGreaterThanOrEqual(10);
    });

    it('cites each label to a source file that exists, inside the app', () => {
        const wrong = labels.filter((l) => !ROOTS.some((root) => l.from.startsWith(root)) || source(l.from) === null);
        expect(wrong.map((l) => `${l.at}: "${l.label}" cites ${l.from}`)).toEqual([]);
    });

    it('finds every quoted label, as a whole phrase, in the file it cites', () => {
        // ⛔ When this fails, a button was renamed: change the manual in the same change as the label, so the
        // manual never names a control the screen no longer shows.
        const missing = labels.filter((l) => {
            const text = source(l.from);
            return text !== null && !containsLabel(text, l.label);
        });
        expect(missing.map((l) => `${l.at}: "${l.label}" is not in ${l.from}`)).toEqual([]);
    });

    it('matches a label only as a whole phrase', () => {
        // The matcher itself, both directions — a matcher that accepted everything would pass the case above.
        expect(containsLabel("label: 'Allow negative numbers'", 'Allow')).toBe(true);
        expect(containsLabel("label: 'Allow negative numbers'", 'All')).toBe(false);
        expect(containsLabel('<span>Add a\n            question here</span>'.replace(/\s+/g, ' '), 'Add a question here')).toBe(true);
        expect(containsLabel("'Saving…'", 'Saving…')).toBe(true);
    });
});

describe('the manual is one well-formed page', () => {
    const chapterIds = manual.map((c) => c.id);
    const sectionIds = manual.flatMap((c) => c.sections.map((s) => s.id));
    const ids = [...chapterIds, ...sectionIds];

    it('has an id for every chapter and section that is unique, and safe in an address', () => {
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids.filter((id) => !/^[a-z][a-z0-9-]*$/.test(id))).toEqual([]);
        // The page gives each chapter heading the id `<chapter>-heading`; a section must not claim one of those.
        expect(ids.filter((id) => id.endsWith('-heading'))).toEqual([]);
    });

    it('leaves no chapter, section or block empty', () => {
        expect(manual.length).toBeGreaterThanOrEqual(10);
        for (const chapter of manual) {
            expect(chapter.title.trim(), chapter.id).not.toBe('');
            expect(chapter.summary.trim(), chapter.id).not.toBe('');
            expect(chapter.sections.length, chapter.id).toBeGreaterThan(0);
            for (const section of chapter.sections) {
                expect(section.title.trim(), section.id).not.toBe('');
                expect(section.blocks.length, section.id).toBeGreaterThan(0);
            }
        }
    });

    it('links only to a chapter or section of this page, or to a page of the app menu', () => {
        const navHrefs = new Set(navItems.map((item) => item.href));
        const broken = manualLinks().filter((l) => (l.href.startsWith('#') ? !ids.includes(l.href.slice(1)) : !navHrefs.has(l.href)));
        expect(broken.map((l) => `${l.at}: ${l.href}`)).toEqual([]);
    });
});
