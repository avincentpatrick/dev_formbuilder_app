import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

/**
 * M130 (`R-c9f50df2`) — THE ONE RAW-HTML SINK IN THE FRONT END, CENSUSED RATHER THAN REMEMBERED.
 *
 * ── WHY THIS EXISTS NOW ──────────────────────────────────────────────────────────────────────────────────
 * `PublicRuntimeSecurityHeaders` sets no `script-src`, deliberately, so OUTPUT ENCODING IS THE ONLY CONTROL on
 * the guest page (`docs/piping-output-encoding-design.md` §1/§5) — and since M130 that page renders text an
 * author composed (a note's content blocks). A well-meaning edit that turns the block renderer, or any other
 * component, into a markup sink would be an XSS on every respondent, and until this file nothing in the
 * repository could see one: `components.test.ts` mentions the invariant in a COMMENT, and
 * `ContentBlocksEditor.test.ts` pins a single file.
 *
 * ── WHAT IT COUNTS ───────────────────────────────────────────────────────────────────────────────────────
 * Every `.vue` and `.ts` under `resources/` and `packages/design-system/src` — the guest bundle ships the
 * design system too — except tests, stories and build output. In a template: `v-html`, and an `innerHTML` /
 * `outerHTML` bound as a property. In script: an assignment to `innerHTML`/`outerHTML`, `insertAdjacentHTML(`,
 * `document.write(`, and an `innerHTML:` key (a render function's props). Template comments and script
 * comments are masked first, because a rule about a forbidden construct cannot be documented by quoting it —
 * `SharePanel.vue` and `ContentBlocksEditor.vue` both explain why they do NOT use one.
 *
 * ── THE ONE ALLOWED SINK ─────────────────────────────────────────────────────────────────────────────────
 * `TwoFactorSetup.vue`'s `.tfa__qr`: a server-generated, same-origin 2FA QR SVG that must never be given form
 * content. Asserted EXACTLY, so a second sink fails and so does the first one disappearing unnoticed.
 *
 * ⚠️ A GATE WRITTEN HERE PROVES NOTHING WHILE GREEN. The in-test controls below prove the detector reads each
 * shape; M130 also committed a `v-html` into `NoteContent.vue`, watched this file go red, and reverted it.
 */

const ROOTS = ['resources', 'packages/design-system/src'];

const ALLOWED: ReadonlyArray<{ file: string; kind: string }> = [{ file: 'resources/js/components/settings/TwoFactorSetup.vue', kind: 'v-html' }];

type Sink = { file: string; line: number; kind: string };

/** Blank a region out while preserving its newlines, so match indices still map to real line numbers. */
function blank(region: string): string {
    return region.replace(/[^\n]/g, ' ');
}

/** The template block of an SFC with its HTML comments masked, and the rest of the file masked entirely. */
function templateOf(source: string): string {
    const open = /^<template(?:\s[^>]*)?>/m.exec(source);
    if (open === null) return blank(source);
    const start = open.index + open[0].length;
    let end = -1;
    for (const close of source.matchAll(/^<\/template>/gm)) {
        if (close.index !== undefined && close.index >= start) end = close.index;
    }
    if (end === -1) return blank(source);

    return (blank(source.slice(0, start)) + source.slice(start, end) + blank(source.slice(end))).replace(/<!--[\s\S]*?-->/g, blank);
}

/** Script text with block and line comments masked. A `//` right after a colon is a URL, not a comment. */
function scriptOf(source: string, isVue: boolean): string {
    let text = source;
    if (isVue) {
        const template = templateOf(source);
        // Everything that is NOT the template: mask the template region out of the source.
        text = source
            .split('')
            .map((char, i) => (template[i] !== ' ' && template[i] !== '\n' && char !== '\n' ? ' ' : char))
            .join('');
    }

    return text.replace(/\/\*[\s\S]*?\*\//g, blank).replace(/(?<!:)\/\/[^\n]*/g, blank);
}

const TEMPLATE_SINKS: ReadonlyArray<[string, RegExp]> = [
    ['v-html', /\bv-html\s*=/g],
    ['bound innerHTML', /(?:\s|^)(?::|v-bind:)(?:innerHTML|outerHTML)\s*=/g],
];

const SCRIPT_SINKS: ReadonlyArray<[string, RegExp]> = [
    ['innerHTML assignment', /\.(?:innerHTML|outerHTML)\s*(?:\+?=)(?!=)/g],
    ['insertAdjacentHTML', /\binsertAdjacentHTML\s*\(/g],
    ['document.write', /\bdocument\.write(?:ln)?\s*\(/g],
    ['innerHTML prop', /(?:[{,]\s*|^\s*)['"]?innerHTML['"]?\s*:/gm],
];

/** Every sink in one source text — exported shape for the controls below, so they test the real detector. */
function sinksIn(source: string, file: string, isVue: boolean): Sink[] {
    const found: Sink[] = [];
    const lineOf = (text: string, index: number): number => text.slice(0, index).split('\n').length;

    if (isVue) {
        const template = templateOf(source);
        for (const [kind, pattern] of TEMPLATE_SINKS) {
            for (const match of template.matchAll(pattern)) found.push({ file, line: lineOf(template, match.index ?? 0), kind });
        }
    }

    const script = scriptOf(source, isVue);
    for (const [kind, pattern] of SCRIPT_SINKS) {
        for (const match of script.matchAll(pattern)) found.push({ file, line: lineOf(script, match.index ?? 0), kind });
    }

    return found;
}

function sourceFilesUnder(root: string): string[] {
    const found: string[] = [];
    for (const entry of readdirSync(root, { withFileTypes: true })) {
        if (['node_modules', 'dist', 'storybook-static', '__tests__'].includes(entry.name)) continue;
        const path = join(root, entry.name);
        if (entry.isDirectory()) {
            found.push(...sourceFilesUnder(path));
        } else if (
            (entry.name.endsWith('.vue') || entry.name.endsWith('.ts')) &&
            !entry.name.endsWith('.test.ts') &&
            !entry.name.endsWith('.stories.ts') &&
            !entry.name.endsWith('.d.ts')
        ) {
            found.push(path);
        }
    }

    return found;
}

describe('resources/ and the design system — exactly one raw-HTML sink', () => {
    const files = ROOTS.flatMap((root) => sourceFilesUnder(join(process.cwd(), root)));
    const sinks = files.flatMap((path) =>
        sinksIn(readFileSync(path, 'utf8'), relative(process.cwd(), path).split(sep).join('/'), path.endsWith('.vue')),
    );

    it('actually walked both trees', () => {
        // ⛔ A walk that matched nothing would pass the census below against an empty list. The floors are
        // MEASURED (324 files, 56 of them in the design system, at M130), with room to delete a few; the first
        // draft guessed 400 and its own first run said otherwise.
        expect(files.length, 'too few source files — the walk is broken, not the tree').toBeGreaterThan(250);
        expect(files.filter((path) => path.split(sep).join('/').includes('packages/design-system/src')).length, 'the design system was not walked').toBeGreaterThan(40);
        expect(files.some((path) => path.endsWith(join('submissions', 'NoteContent.vue'))), 'the content-block renderer was not walked').toBe(true);
    });

    it('finds the one allowed sink and nothing else', () => {
        expect(sinks.map(({ file, kind }) => ({ file, kind }))).toEqual(ALLOWED);
    });

    it('detects every sink shape it claims to, in a synthetic file', () => {
        // The positive controls the census needs: each pattern, fed the shape it names, reports it.
        expect(sinksIn('<template>\n  <div v-html="x" />\n</template>\n', 'x.vue', true).map((s) => s.kind)).toEqual(['v-html']);
        expect(sinksIn('<template>\n  <div :innerHTML="x" />\n</template>\n', 'x.vue', true).map((s) => s.kind)).toEqual(['bound innerHTML']);
        expect(sinksIn('el.innerHTML = x;\nel.outerHTML += y;\n', 'x.ts', false).map((s) => s.kind)).toEqual(['innerHTML assignment', 'innerHTML assignment']);
        expect(sinksIn("el.insertAdjacentHTML('beforeend', x);\ndocument.write(x);\n", 'x.ts', false).map((s) => s.kind)).toEqual(['insertAdjacentHTML', 'document.write']);
        expect(sinksIn("h('div', { innerHTML: x });\n", 'x.ts', false).map((s) => s.kind)).toEqual(['innerHTML prop']);
    });

    it('does not count prose about a sink, a comparison, or a URL', () => {
        // The negative controls: the census must not cry wolf, or somebody deletes it and the rule goes with it.
        expect(sinksIn('<template>\n  <!-- never v-html="x" here -->\n  <p>ok</p>\n</template>\n', 'x.vue', true)).toEqual([]);
        expect(sinksIn('// el.innerHTML = x is forbidden\n/* document.write(x) */\nif (el.innerHTML === y) {}\n', 'x.ts', false)).toEqual([]);
        expect(sinksIn("const u = 'https://example.org'; el.innerHTML = x;\n", 'x.ts', false).map((s) => s.kind)).toEqual(['innerHTML assignment']);
    });
});
