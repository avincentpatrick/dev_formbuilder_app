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

/**
 * Both cases below carry an explicit timeout, because Vitest's 5s default is the wrong budget for what
 * they actually do: a synchronous walk of `resources/` and `packages/design-system/src/` reading every
 * `.vue`/`.css`/`.ts` off disk, twice. That has been a recorded intermittent timeout since H21b — and it
 * is a genuinely dangerous flake, because H21d1 found a REAL dangling token behind one of these
 * timeouts, which means "it timed out again" is not safe to wave through.
 *
 * H24b1 is why it is fixed rather than tolerated: this increment adds files to the very tree being
 * walked, so leaving a marginal budget in place would push an already-flaky job toward failing on load
 * rather than on a defect. The walk itself is ~2s in isolation and ~4-8s under a loaded container; 30s
 * is far above either and still fails fast if the scan ever hangs.
 */
const WALK_TIMEOUT_MS = 30_000;

describe('design token references', () => {
    it('resolves every var(--mds-*) reference to a defined token', () => {
        const defined = definedTokens();
        const referenced = referencedTokens();

        const dangling = [...referenced.entries()]
            .filter(([name]) => !defined.has(name))
            .map(([name, sites]) => `${name}  ←  ${[...new Set(sites)].join(', ')}`)
            .sort();

        expect(dangling, `Undefined --mds-* tokens referenced:\n${dangling.join('\n')}`).toEqual([]);
    }, WALK_TIMEOUT_MS);

    it('finds a non-trivial number of tokens on both sides (the scan is actually working)', () => {
        // Guards against a silent regression where a path change makes both sets empty and the
        // assertion above passes vacuously.
        expect(definedTokens().size).toBeGreaterThan(100);
        expect(referencedTokens().size).toBeGreaterThan(50);
    }, WALK_TIMEOUT_MS);
});

/**
 * Where the APPLICATION references tokens, as distinct from where the design system does. The rule below
 * is asymmetric on purpose: `packages/design-system/src` is the layer whose whole job is to turn ramp
 * steps into semantic names, so a primitive there is correct and a primitive here is the bug.
 */
const APP_SCAN_ROOT = join(repoRoot, 'resources');

/** The token file whose names ARE the primitive ramps: primary, neutral, brass, accent-teal, success, warning, danger. */
const PRIMITIVE_TOKEN_JSON = join(TOKEN_JSON_DIR, 'primitive.json');

function primitiveTokenNames(): Set<string> {
    const json: unknown = JSON.parse(readFileSync(PRIMITIVE_TOKEN_JSON, 'utf8'));
    return new Set(flattenTokenNames(json));
}

function appTokenReferences(): { name: string; site: string }[] {
    const out: { name: string; site: string }[] = [];

    for (const file of walk(APP_SCAN_ROOT)) {
        const source = stripBlockComments(readFileSync(file, 'utf8'));
        for (const match of source.matchAll(/var\(\s*(--mds-[\w-]+)/g)) {
            out.push({ name: match[1], site: file.slice(repoRoot.length + 1).replace(/\\/g, '/') });
        }
    }

    return out;
}

/**
 * Guard (M23): application code references SEMANTIC tokens, never a primitive ramp step.
 *
 * ⛔ THE INSTANCE THAT BOUGHT THIS GATE IS THE ARGUMENT FOR IT, BECAUSE IT WAS INVISIBLE TO EVERY OTHER
 * ONE. `resources/js/Pages/achievements/Index.vue` painted the unearned-badge medallion
 * `background-color: var(--mds-neutral-100)`. That token exists, so the case above it stayed green; it is
 * a legal colour, so no lint saw it; and the achievements page is scanned by axe at three viewports in
 * two themes, which also saw nothing — because in DARK MODE `theme-overrides.css:113` re-points
 * `--mds-color-bg-surface` at `--mds-neutral-100`, so the disc was painted exactly its own card's colour.
 * A 1.00:1 element is not a contrast violation to report; it is an element that is not there. The badge
 * shelf simply lost its medallions after dark, and the only gate that could ever have caught it is one
 * that objects to the REFERENCE rather than to the rendered result.
 *
 * ⚠️ AND THE REASON THE RULE IS "NO PRIMITIVES" RATHER THAN "NOT THAT ONE": a primitive is a ramp step,
 * and the dark theme re-points ramp steps wholesale (`--mds-neutral-*` inverts end to end at
 * `theme-overrides.css:173-184`). Every primitive reference in application code is therefore a colour
 * chosen in one theme and inherited blind in the other. This one happened to land on the surface colour;
 * the next one lands somewhere else and is just as unowned.
 *
 * The primitive set is READ FROM `primitive.json` rather than hand-listed, so adding an eighth ramp
 * extends this gate for free and renaming one cannot silently narrow it.
 */
describe('design tokens — the application references semantics, not ramps', () => {
    it('finds no var(--mds-<ramp>-<step>) anywhere under resources/', () => {
        const primitives = primitiveTokenNames();

        const offenders = appTokenReferences()
            .filter(({ name }) => primitives.has(name))
            .map(({ name, site }) => `${site}  →  var(${name})`)
            .sort();

        expect(
            [...new Set(offenders)],
            `Primitive ramp tokens referenced from application code (use a --mds-color-* semantic instead):\n${offenders.join('\n')}`,
        ).toEqual([]);
    }, WALK_TIMEOUT_MS);

    it('is scanning something — the primitive set and the reference set are both non-trivial', () => {
        // The anti-vacuity case, and it is not ceremony here: this gate asserts an EMPTY result, so a
        // mistyped root or a walk that silently returns nothing passes it forever while measuring no
        // files at all. Both halves have to be real for the emptiness above to mean anything.
        expect(primitiveTokenNames().size).toBeGreaterThan(50);
        expect(appTokenReferences().length).toBeGreaterThan(200);
    }, WALK_TIMEOUT_MS);
});
