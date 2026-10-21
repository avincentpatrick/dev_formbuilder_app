import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Every letter-spacing is a token (M182, `R-f724595b`, `D159`). The product's uppercase micro-labels — the table
 * header, the stat-tile label, the filter bar's, the sidebar's group names, the Help page's contents — share one
 * letting, `--mds-tracking-wide`; `FilterBar.vue` records that the token exists for exactly them. Literals had crept
 * back beside it (0.03em, 0.04em, 0.06em), so labels of one role read three ways. One row fixed three of them and the
 * builder held three more, which is why this is a sweep rather than another row.
 *
 * ⛔ THE ALLOW-LIST ONLY SHRINKS. Each kept literal is not a micro-label: a wordmark spaced as a logotype, the sign-in
 * title, a monospace secret spaced for reading aloud. The second case fails when a kept literal disappears, so a
 * stale entry cannot linger to excuse the next one.
 */

const repoRoot = process.cwd();
const SCAN_ROOTS = [join(repoRoot, 'resources'), join(repoRoot, 'packages/design-system/src')];

/** `<file>  →  <value>` for each literal kept on purpose, and why. */
const KEPT: Record<string, string> = {
    'resources/js/Layouts/AdminLayout.vue  →  0.03em': 'the console wordmark',
    'resources/js/Layouts/AuthLayout.vue  →  0.03em': 'the sign-in wordmark',
    'resources/js/Layouts/AuthLayout.vue  →  0.02em': 'the sign-in title, an uppercase heading',
    'resources/js/components/shell/TopNav.vue  →  0.03em': 'the top bar wordmark',
    'resources/js/components/settings/TwoFactorSetup.vue  →  0.06em': 'the two-step key, monospace, spaced to read aloud',
};

function walk(dir: string): string[] {
    const out: string[] = [];
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'dist' || entry === '__tests__') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...walk(full));
        else if (entry.endsWith('.vue') || entry.endsWith('.css')) out.push(full);
    }
    return out;
}

/** Every `letter-spacing` declaration in a component or stylesheet, comments stripped. */
function declarations(): { site: string; value: string }[] {
    const out: { site: string; value: string }[] = [];
    for (const root of SCAN_ROOTS) {
        for (const file of walk(root)) {
            const source = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
            for (const match of source.matchAll(/(?<![\w-])letter-spacing:\s*([^;}]+)/g)) {
                out.push({ site: file.slice(repoRoot.length + 1).replace(/\\/g, '/'), value: match[1].trim() });
            }
        }
    }
    return out;
}

const literals = (): string[] =>
    declarations()
        .filter(({ value }) => !/^var\(--mds-/.test(value) && value !== 'inherit' && value !== 'normal')
        .map(({ site, value }) => `${site}  →  ${value}`);

describe('letter-spacing comes from a token', () => {
    it('finds no literal letter-spacing outside the kept list', () => {
        const offenders = [...new Set(literals().filter((literal) => !(literal in KEPT)))].sort();

        expect(offenders, `Use var(--mds-tracking-wide) for an uppercase micro-label:\n${offenders.join('\n')}`).toEqual([]);
    }, 30_000);

    it('keeps no stale entry: every kept literal is still in the tree', () => {
        const present = new Set(literals());

        expect(Object.keys(KEPT).filter((kept) => !present.has(kept))).toEqual([]);
    }, 30_000);

    it('is scanning something', () => {
        // Anti-vacuity: the first case asserts an empty list, which a walk that reads nothing would satisfy forever.
        expect(declarations().length).toBeGreaterThan(15);
    }, 30_000);
});
