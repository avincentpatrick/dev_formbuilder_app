import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { containerBlock, nested, rule, styleSlice } from '../../__tests__/css-source';

const read = (path: string): string => readFileSync(join(process.cwd(), path), 'utf8');
const styleOf = (path: string): string => styleSlice(read(path));

/**
 * The manual-entry page keeps its questions in a reading column and carries a side panel beside them (M174,
 * `R-66952d33`, `D121`, `D136`): from a 75rem page the form's name and version, the open or closed notice and the
 * form's sections sit in a 26rem column to the right of a 45rem form, instead of a 720px column with the rest of a
 * wide screen empty. A response being checked against its scanned paper keeps its paper and form halves (`M168`).
 * happy-dom lays out nothing; `tests/e2e/list-layout.spec.ts` measures the panel, and
 * `ocr-review-axe.spec.ts`'s `expectPaperBeside` the halves. This file pins the source shape.
 */

const ENCODE = 'resources/js/Pages/submissions/Encode.vue';

describe('manual entry — a reading column with the side panel beside it', () => {
    it('drops the 720px cap and declares the page as the container the split reads', () => {
        const css = styleOf(ENCODE);

        expect(css).not.toMatch(/^\s+max-width:\s*720px/m);
        expect(rule(css, '.encode')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the panel and the form, the panel first, the intro and the schedule notice in the panel', () => {
        const source = read(ENCODE);
        const layout = source.indexOf('<div class="encode__layout">');
        const aside = source.indexOf('<div class="encode__aside">');
        const main = source.indexOf('<div class="encode__main">');

        expect(layout).toBeGreaterThan(-1);
        expect(aside).toBeGreaterThan(layout);
        expect(main).toBeGreaterThan(aside);
        expect(source.indexOf('<p class="encode__intro">')).toBeGreaterThan(aside);
        expect(source.indexOf('<p class="encode__intro">')).toBeLessThan(main);
        expect(source.indexOf('class="encode__schedule-banner"')).toBeLessThan(main);
        expect(source.indexOf('<form class="encode__form"')).toBeGreaterThan(main);
    });

    it('holds the form at 45rem with the panel beside it from a 75rem container, never beside a scanned paper', () => {
        const block = containerBlock(styleOf(ENCODE), '75rem');
        const layout = nested(block, '.encode:not(.encode--scan) .encode__layout');

        expect(layout, '@container (min-width: 75rem) .encode:not(.encode--scan) .encode__layout').not.toBeNull();
        expect(layout).toMatch(/grid-template-columns:\s*minmax\(0,\s*45rem\)\s+minmax\(18rem,\s*26rem\)/);
        expect(nested(block, '.encode:not(.encode--scan) .encode__aside')).toMatch(/grid-column:\s*2/);
        expect(nested(block, '.encode:not(.encode--scan) .encode__main')).toMatch(/grid-column:\s*1/);
    });

    it('keeps a 45rem column below the split, as the 720px cap did', () => {
        expect(rule(styleOf(ENCODE), '.encode__layout')).toMatch(/max-inline-size:\s*45rem/);
    });

    it('moves the scanned paper\'s halves onto the form\'s own wrapper, keeping them equal', () => {
        const css = styleOf(ENCODE);

        expect(css).toMatch(/^ {4}\.encode--scan \.encode__main \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\) minmax\(0, 1fr\);/m);
        expect(css).toMatch(/^ {4}\.encode--scan \.encode__main > \.encode__scan-pages \{\s*grid-column: 1;/m);
        expect(css).toMatch(/^ {4}\.encode--scan \.encode__main > \.encode__form \{\s*grid-column: 2;/m);
    });

    it('sets its small error text in the on-surface danger pair, never the dark-failing danger-text token', () => {
        const css = styleOf(ENCODE);

        expect(css).not.toContain('--mds-color-danger-text');
        expect(rule(css, '.encode__count-error')).toContain('--mds-color-status-danger-fg');
    });
});
