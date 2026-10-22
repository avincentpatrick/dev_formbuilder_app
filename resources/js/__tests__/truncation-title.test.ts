import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * A value cut off with an ellipsis can be read in full where it is shown (M182, `R-9d3c2439`, `D159`). The row named
 * three — a webhook's URL, a rule's destination, a delivery's reply — and a sweep of `text-overflow: ellipsis` found
 * eight more, each a name or a title a person typed or a provider sent. The remedy is the element's `title`, carrying
 * the text it renders: no tooltip component is owed, because the text is already in the page (clipped by CSS, never
 * removed), so a screen reader reads it whole and the title exposes nothing new.
 *
 * The sweep reads each component's CSS for a rule that clips with an ellipsis, then every element in its template
 * carrying that class, and requires a `title` binding on it. A rule whose selector ends in an element rather than a
 * class, or whose text is fixed words, is named in KEPT with its reason; the list only shrinks.
 */

const repoRoot = process.cwd();
const SCAN_ROOTS = [join(repoRoot, 'resources'), join(repoRoot, 'packages/design-system/src')];

/** `<file>  →  <selector>` for each clipped string that is fixed words, not a value. */
const KEPT: Record<string, string> = {
    'resources/js/components/shell/TopNav.vue  →  .topnav__wordmark': 'the product name, "Meridian"',
    'resources/js/components/forms/FormCard.vue  →  .form-card__stat dt': 'the stat labels: Responses, In progress, Last',
};

function walk(dir: string): string[] {
    const out: string[] = [];
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'dist' || entry === '__tests__') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...walk(full));
        else if (entry.endsWith('.vue')) out.push(full);
    }
    return out;
}

/** The opening tag that starts at `start`, read to its closing `>` outside any quoted attribute value. */
function tagAt(source: string, start: number): string {
    let quote: string | null = null;
    for (let i = start + 1; i < source.length; i++) {
        const c = source[i];
        if (quote !== null) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") {
            quote = c;
        } else if (c === '>') {
            return source.slice(start, i + 1);
        }
    }
    return source.slice(start);
}

interface Clipped {
    site: string;
    selector: string;
    tags: string[];
}

/** Every rule that clips with an ellipsis, with the template tags carrying its class. */
function clipped(): Clipped[] {
    const out: Clipped[] = [];
    for (const root of SCAN_ROOTS) {
        for (const file of walk(root)) {
            const source = readFileSync(file, 'utf8');
            const site = file.slice(repoRoot.length + 1).replace(/\\/g, '/');
            // The block itself, at column 0: a template comment may mention "the `<style>` block" (RuleShow does).
            const styleAt = source.search(/^<style\b/m);
            if (styleAt === -1) continue;

            const css = source.slice(styleAt).replace(/\/\*[\s\S]*?\*\//g, '');
            const templateStart = source.indexOf('<template');
            const template = templateStart === -1 ? '' : source.slice(templateStart, source.lastIndexOf('</template>', styleAt));

            for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
                if (!/text-overflow:\s*ellipsis/.test(match[2])) continue;

                for (const selector of match[1].split(',').map((s) => s.trim())) {
                    const cls = selector.match(/\.([\w-]+)$/)?.[1];
                    const tags: string[] = [];
                    if (cls !== undefined) {
                        for (const use of template.matchAll(new RegExp(`(?<![\\w-])${cls}(?![\\w-])`, 'g'))) {
                            const start = template.lastIndexOf('<', use.index);
                            if (template[start + 1] !== '/') tags.push(tagAt(template, start));
                        }
                    }
                    out.push({ site, selector, tags: [...new Set(tags)] });
                }
            }
        }
    }
    return out;
}

const hasTitle = (tag: string): boolean => /\s(?::|v-bind:)?title=/.test(tag);

describe('a value cut off with an ellipsis carries its whole text in a title', () => {
    it('finds no clipped value without a title, outside the kept list', () => {
        const offenders = clipped()
            .filter(({ site, selector }) => !(`${site}  →  ${selector}` in KEPT))
            .flatMap(({ site, selector, tags }) =>
                tags.length === 0
                    ? [`${site}  →  ${selector}  (no element in the template carries it)`]
                    : tags.filter((tag) => !hasTitle(tag)).map(() => `${site}  →  ${selector}`),
            );

        expect([...new Set(offenders)].sort(), `Bind :title to the text the element renders:\n${offenders.join('\n')}`).toEqual([]);
    }, 30_000);

    it('keeps no stale entry: every kept selector still clips', () => {
        const present = new Set(clipped().map(({ site, selector }) => `${site}  →  ${selector}`));

        expect(Object.keys(KEPT).filter((kept) => !present.has(kept))).toEqual([]);
    }, 30_000);

    it('is scanning something', () => {
        // Anti-vacuity: an empty list passes the first case, so the sweep must see the clipped rules it exists for.
        expect(clipped().length).toBeGreaterThanOrEqual(13);
    }, 30_000);
});
