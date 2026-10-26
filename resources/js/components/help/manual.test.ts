import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { MANUAL_PARTS, manual, manualFigures, manualLinks, quotedLabels } from './manual';
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

describe('the Builder reference covers the whole builder (M163, `R-ccc0be00`, `D115`)', () => {
    const reference = manual.filter((chapter) => chapter.part === 'reference');
    const referenceLabels = quotedLabels(reference);

    it('puts every chapter in a known part, the walk-through first', () => {
        const keys = MANUAL_PARTS.map((part) => part.key);
        expect(manual.filter((chapter) => !keys.includes(chapter.part)).map((c) => c.id)).toEqual([]);
        const order = manual.map((chapter) => keys.indexOf(chapter.part));
        expect(order).toEqual([...order].sort((a, b) => a - b));
        expect(reference.length).toBeGreaterThanOrEqual(8);
    });

    it('names every question type the builder offers', () => {
        // ⛔ The user asked for "each input type". The types are read from the enum that defines them, so a type
        // added to the builder and left out of the reference reddens this case rather than going unexplained.
        const enumSource = readFileSync(join(process.cwd(), 'app/Enums/FieldType.php'), 'utf8');
        // Only `label()`'s own match — the enum has other `self::X => '…'` arms (its config editors, for one).
        const labelBody = enumSource.split('public function label(): string')[1]?.split('};')[0] ?? '';
        const types = [...labelBody.matchAll(/self::\w+ => '([^']+)',/g)].map((m) => m[1]);
        // Anti-vacuity: a renamed method or a changed arm shape would otherwise leave nothing to check.
        expect(types.length).toBeGreaterThanOrEqual(31);
        const named = new Set(referenceLabels.filter((l) => l.from === 'app/Enums/FieldType.php').map((l) => l.label));
        expect([...new Set(types)].filter((type) => !named.has(type))).toEqual([]);
    });

    it('shows what each question type can do, one row per type and a cell for every surface (M187, R-3e6358ed)', () => {
        // The table is `field-readiness.json`, written from the rules by `php artisan help:readiness` and held equal to
        // them by `FieldReadinessTableTest`; this holds the page's side — every type once, nothing dropped on the way in.
        const tables = manual.flatMap((c) => c.sections.flatMap((s) => s.blocks)).filter((b) => b.kind === 'table');
        expect(tables).toHaveLength(1);
        const table = tables[0];
        if (table.kind !== 'table') throw new Error('unreachable');

        const enumSource = readFileSync(join(process.cwd(), 'app/Enums/FieldType.php'), 'utf8');
        const cases = [...enumSource.matchAll(/^\s*case \w+ = '([a-z_]+)';/gm)].map((m) => m[1]);
        expect(cases.length).toBeGreaterThanOrEqual(31);
        expect(table.rows.map((row) => row.key)).toEqual(cases);

        const surfaces = table.columns.map((c) => c.key).filter((key) => key !== 'type');
        expect(surfaces).toEqual(['builder', 'answering', 'paper', 'scan', 'pdf', 'spreadsheet', 'analytics', 'conditions']);
        for (const row of table.rows) {
            expect(Object.keys(row.cells), row.key).toEqual(surfaces);
            expect(Object.values(row.cells).every((cell) => cell.length > 0), row.key).toBe(true);
        }

        // The testers asked whether the Adv types are ready: each is answered on both channels, and says Adv.
        const signature = table.rows.find((row) => row.key === 'signature')!;
        expect(signature.cells.answering).toBe('Yes');
        expect(signature.head.some((span) => span.kind === 'ui' && span.label === 'Adv')).toBe(true);
        expect(table.rows.find((row) => row.key === 'duration')!.head.some((span) => span.kind === 'text' && span.text.includes('retired'))).toBe(true);
    });

    it('names every tab the right panel can show', () => {
        const panel = readFileSync(join(process.cwd(), 'resources/js/components/builder/ConfigPanel.vue'), 'utf8');
        const tabs = [...panel.matchAll(/\{ key: '[a-z_]+', label: '([^']+)' \}/g)].map((m) => m[1]);
        expect(new Set(tabs).size).toBeGreaterThanOrEqual(10);
        const named = new Set(referenceLabels.filter((l) => l.from === 'resources/js/components/builder/ConfigPanel.vue').map((l) => l.label));
        expect([...new Set(tabs)].filter((tab) => !named.has(tab))).toEqual([]);
    });

    it('shows each panel in a picture that exists, at half its pixel size', () => {
        // The pictures are written by `tests/e2e/capture/help-pictures.capture.ts` at a device scale of 2. A re-capture
        // that changes a picture's size reddens this until `manual.ts` follows — the cue to re-read the numbered list
        // beside it, whose numbers are the picture's badges.
        const figures = manualFigures();
        expect(figures.map((f) => f.src).sort()).toEqual(['/images/help/left-panel.png', '/images/help/right-panel.png']);
        for (const figure of figures) {
            const full = join(process.cwd(), 'public', figure.src);
            expect(existsSync(full), figure.src).toBe(true);
            const png = readFileSync(full);
            expect(png.subarray(1, 4).toString('latin1'), `${figure.src} is not a PNG`).toBe('PNG');
            // IHDR: width and height are big-endian 32-bit integers at bytes 16 and 20.
            expect({ width: png.readUInt32BE(16) / 2, height: png.readUInt32BE(20) / 2 }, figure.src).toEqual({ width: figure.width, height: figure.height });
            expect(figure.alt.length, `${figure.src} needs a description`).toBeGreaterThan(40);
        }
    });
});
