/**
 * Reading a single-file component's CSS as source text, for the layout pins (M174). happy-dom lays out nothing, so a
 * page's columns are measured in a real browser (`tests/e2e/list-layout.spec.ts`); these pins hold the source shape that
 * produces them. Every selector is anchored at a known indent — column 0 for a top-level rule, four spaces inside an
 * at-rule — so a comment quoting a rule can never satisfy a pin.
 *
 * ⚠️ STRING FUNCTIONS ONLY, NO `node:fs`. `vue-tsc` checks this module (it is not a `*.test.ts`) and the app carries no
 * Node types, so each test file reads its own source and passes the text here — the two lines every layout test in this
 * repository already writes.
 */

/** Everything from the first `<style` on: the component's CSS (and, for a component with two blocks, both). */
export const styleSlice = (source: string): string => source.slice(source.indexOf('<style'));

/** The body of a top-level rule written at column 0, or null. */
export function rule(css: string, selector: string): string | null {
    const start = css.indexOf(`\n${selector} {`);

    return start === -1 ? null : css.slice(start, css.indexOf('}', start));
}

/**
 * The whole of a column-0 `@container (min-width: <width>)` block, braces counted, or null. The blocks it reads hold no
 * comments, so no brace inside a comment can miscount.
 */
export function containerBlock(css: string, width: string): string | null {
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
export function nested(block: string | null, selector: string): string | null {
    if (block === null) {
        return null;
    }

    const start = block.indexOf(`\n    ${selector} {`);

    return start === -1 ? null : block.slice(start, block.indexOf('}', start));
}

/** A grid that gains a column as the page widens: more items across, never wider items. */
export const FILLING_GRID = /grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*[0-9.]+rem\),\s*1fr\)\)/;
