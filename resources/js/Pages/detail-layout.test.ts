import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The detail pages gain a side column on a wide screen (M174, `D120`): from a 75rem container a page's facts sit in a
 * 26rem column beside its tables, answers or log, instead of stacking above them so every row runs edge to edge. The
 * DOM order never changes — the facts stay first for a screen reader and on a phone — so the placement is CSS alone.
 * happy-dom lays out nothing, so the columns themselves are measured in a real browser (`tests/e2e/list-layout.spec.ts`,
 * at 1920 and in the console at 1440); this file pins the source shape that produces them.
 *
 * ⚠️ An element cannot query its own size, so each page declares the container on its root and splits an inner
 * wrapper — the `M129` trap `FormSettingsSections.vue` records, and the scans page's shape since `M173`.
 */

const read = (path: string): string => readFileSync(join(process.cwd(), path), 'utf8');
const styleOf = (path: string): string => {
    const source = read(path);

    return source.slice(source.indexOf('<style'));
};

/** The body of a top-level rule written at column 0, or null. */
function rule(css: string, selector: string): string | null {
    const start = css.indexOf(`\n${selector} {`);

    return start === -1 ? null : css.slice(start, css.indexOf('}', start));
}

/**
 * The whole of a column-0 `@container (min-width: <width>)` block, braces counted, or null. Anchored at column 0 so a
 * comment quoting the rule cannot satisfy it; the blocks it reads hold no comments, so a brace inside one cannot
 * miscount.
 */
function containerBlock(css: string, width: string): string | null {
    const head = `\n@container (min-width: ${width}) {`;
    const start = css.indexOf(head);

    if (start === -1) {
        return null;
    }

    let depth = 0;

    for (let i = start + head.length - 1; i < css.length; i++) {
        if (css[i] === '{') {
            depth++;
        } else if (css[i] === '}' && --depth === 0) {
            return css.slice(start, i + 1);
        }
    }

    return null;
}

/** The body of a rule nested one level inside a block (four spaces in), or null. */
function nested(block: string | null, selector: string): string | null {
    if (block === null) {
        return null;
    }

    const start = block.indexOf(`\n    ${selector} {`);

    return start === -1 ? null : block.slice(start, block.indexOf('}', start));
}

/** Main content first and widest; the facts in a bounded column beside it. */
const ASIDE_SPLIT = /grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(18rem,\s*26rem\)/;

const HUB = 'resources/js/Pages/forms/Show.vue';
const SUBMISSION = 'resources/js/Pages/submissions/Show.vue';

describe('form hub — the versions sit beside the latest responses', () => {
    it('declares the page root as the container the split reads', () => {
        expect(read(HUB)).toMatch(/^ {4}<div class="hub">$/m);
        expect(rule(styleOf(HUB), '.hub')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the two panels, latest first, so the DOM order is the visual order', () => {
        const source = read(HUB);
        const wrapper = source.indexOf('<div class="hub__panels">');
        const latest = source.indexOf('<section class="hub__panel hub__panel--latest">');
        const versions = source.indexOf('<section class="hub__panel hub__panel--versions">');

        expect(wrapper).toBeGreaterThan(-1);
        expect(latest).toBeGreaterThan(wrapper);
        expect(versions).toBeGreaterThan(latest);
    });

    it('splits the panels from a 75rem container, the versions in the bounded column', () => {
        const body = nested(containerBlock(styleOf(HUB), '75rem'), '.hub__panels');

        expect(body, '@container (min-width: 75rem) .hub__panels').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(ASIDE_SPLIT);
        expect(body).toMatch(/align-items:\s*start/);
    });
});

describe('submission page — the facts sit beside the answers', () => {
    it('declares the page root as the container the split reads', () => {
        expect(read(SUBMISSION)).toMatch(/^ {4}<div class="detail">$/m);
        expect(rule(styleOf(SUBMISSION), '.detail')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the fact cards and the answers, facts first, and marks a scanned response', () => {
        const source = read(SUBMISSION);
        const layout = source.indexOf('class="detail__layout"');

        expect(layout).toBeGreaterThan(-1);
        expect(source.indexOf('<div class="detail__grid">')).toBeGreaterThan(layout);
        expect(source).toContain(":class=\"{ 'detail__layout--scan': scan_pages.length > 0 }\"");
    });

    it('places the facts in the bounded column from a 75rem container — never beside a scanned paper', () => {
        const block = containerBlock(styleOf(SUBMISSION), '75rem');
        const layout = nested(block, '.detail__layout:not(.detail__layout--scan)');

        expect(layout, '@container (min-width: 75rem) .detail__layout:not(.detail__layout--scan)').not.toBeNull();
        expect(layout).toMatch(/display:\s*grid/);
        expect(layout).toMatch(ASIDE_SPLIT);
        expect(nested(block, '.detail__layout:not(.detail__layout--scan) > .detail__grid')).toMatch(/grid-column:\s*2/);
        expect(nested(block, '.detail__layout:not(.detail__layout--scan) > .detail__body')).toMatch(/grid-column:\s*1/);
    });

    it('lays the answer blocks in a grid that gains columns, never wider blocks', () => {
        const body = rule(styleOf(SUBMISSION), '.detail__blocks');

        expect(body, '.detail__blocks').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*[0-9.]+rem\),\s*1fr\)\)/);
        expect(body).toMatch(/align-items:\s*start/);
    });
});
