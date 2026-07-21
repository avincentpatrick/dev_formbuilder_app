import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guard: every `var(--mds-…)` reference in the app and the design system must resolve to a token
 * that actually exists.
 *
 * G11 found SIXTEEN referenced-but-undefined `--mds-*` names across the tree (e.g.
 * `--mds-color-text-muted`, `--mds-type-heading-sm-font-size`, `--mds-font-size-body`). Where no
 * fallback was supplied, the whole declaration is invalid at computed-value time and the property
 * silently falls back to its inherited value — which is why this is not merely tidiness: under
 * `[data-font-size]` (§2.9) such an element scales by INHERITANCE rather than by its intended type
 * role, so the personalization feature would have produced wrong sizes on the encode page and the
 * template gallery.
 *
 * A one-time sweep fixes today's instances; this test is what stops the class of bug recurring.
 * Scoped to the `--mds-` prefix so component-local custom properties are ignored.
 */

/**
 * Vitest runs with its config file's directory as cwd, which is the repo root — so this is both
 * deterministic and portable.
 *
 * Deliberately NOT `fileURLToPath(new URL('../../../../../', import.meta.url))`, which works locally
 * but throws ERR_INVALID_URL_SCHEME on CI: the suite runs under `environment: 'happy-dom'`, whose DOM
 * `URL` global shadows Node's, and resolving a five-level *directory* URL (trailing slash) through it
 * does not reliably preserve the file: scheme. The sibling suites in this folder get away with the
 * same pattern because they resolve concrete *file* paths.
 */
const repoRoot = process.cwd();

/** Where tokens are DEFINED: the Style Dictionary sources + the hand-authored theme layer. */
const TOKEN_JSON_DIR = join(repoRoot, 'packages/design-system/tokens');
const THEME_CSS = [
    join(repoRoot, 'packages/design-system/src/theme/theme-overrides.css'),
    join(repoRoot, 'packages/design-system/src/theme/fonts.css'),
];

/** Where tokens are REFERENCED. */
const SCAN_ROOTS = [join(repoRoot, 'resources'), join(repoRoot, 'packages/design-system/src')];
const SCAN_EXTENSIONS = ['.vue', '.css', '.ts'];

function walk(dir: string): string[] {
    const out: string[] = [];
    for (const entry of readdirSync(dir)) {
        // `__tests__` is skipped so this file's own `var(--mds-name` regex literal is not scanned
        // as if it were a real reference.
        if (entry === 'node_modules' || entry === 'dist' || entry === '__tests__') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...walk(full));
        else if (SCAN_EXTENSIONS.some((ext) => entry.endsWith(ext))) out.push(full);
    }
    return out;
}

/**
 * Block comments are stripped before matching: theme-overrides.css documents itself with prose like
 * "emitted as `var(--mds-neutral-*)`", which is not a real reference and would otherwise be reported
 * as a dangling token.
 */
function stripBlockComments(source: string): string {
    return source.replace(/\/\*[\s\S]*?\*\//g, '');
}

/**
 * Flatten a Style Dictionary token file to the custom-property names it generates. build-tokens.mjs
 * uses `token.path.join('-')` with the `mds` prefix, so a nested key path IS the name.
 */
function flattenTokenNames(node: unknown, path: string[] = []): string[] {
    if (node === null || typeof node !== 'object') return [];
    const record = node as Record<string, unknown>;
    // A leaf is the object that carries `value`; its own path is the token name.
    if ('value' in record) return [`--mds-${path.join('-')}`];
    return Object.entries(record).flatMap(([key, child]) => flattenTokenNames(child, [...path, key]));
}

function definedTokens(): Set<string> {
    const defined = new Set<string>();

    for (const file of readdirSync(TOKEN_JSON_DIR).filter((f) => f.endsWith('.json'))) {
        const json: unknown = JSON.parse(readFileSync(join(TOKEN_JSON_DIR, file), 'utf8'));
        for (const name of flattenTokenNames(json)) defined.add(name);
    }

    // Custom properties assigned directly in the theme layer (dark-mode re-points, the accent and
    // text-size overrides, and the private --mds-_* indirection customs).
    for (const file of THEME_CSS) {
        let css: string;
        try {
            css = readFileSync(file, 'utf8');
        } catch {
            continue; // fonts.css is optional
        }
        for (const match of stripBlockComments(css).matchAll(/(--mds-[\w-]+)\s*:/g)) {
            defined.add(match[1]);
        }
    }

    return defined;
}

function referencedTokens(): Map<string, string[]> {
    const referenced = new Map<string, string[]>();

    for (const root of SCAN_ROOTS) {
        for (const file of walk(root)) {
            const source = stripBlockComments(readFileSync(file, 'utf8'));
            // Capture only the token name — the match stops before any `,` fallback value.
            for (const match of source.matchAll(/var\(\s*(--mds-[\w-]+)/g)) {
                const name = match[1];
                const sites = referenced.get(name) ?? [];
                // +1 drops the leading separator, so failures read as `resources/js/…`.
                sites.push(file.slice(repoRoot.length + 1).replace(/\\/g, '/'));
                referenced.set(name, sites);
            }
        }
    }

    return referenced;
}

describe('design token references', () => {
    it('resolves every var(--mds-*) reference to a defined token', () => {
        const defined = definedTokens();
        const referenced = referencedTokens();

        const dangling = [...referenced.entries()]
            .filter(([name]) => !defined.has(name))
            .map(([name, sites]) => `${name}  ←  ${[...new Set(sites)].join(', ')}`)
            .sort();

        expect(dangling, `Undefined --mds-* tokens referenced:\n${dangling.join('\n')}`).toEqual([]);
    });

    it('finds a non-trivial number of tokens on both sides (the scan is actually working)', () => {
        // Guards against a silent regression where a path change makes both sets empty and the
        // assertion above passes vacuously.
        expect(definedTokens().size).toBeGreaterThan(100);
        expect(referencedTokens().size).toBeGreaterThan(50);
    });
});
