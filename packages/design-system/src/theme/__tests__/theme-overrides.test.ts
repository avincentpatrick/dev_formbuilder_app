import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Structural guards for the theme layer.
 *
 * Dark mode has to be expressed TWICE — once for the explicit `[data-theme-mode='dark']` opt-in and
 * once inside `@media (prefers-color-scheme: dark)` for the "system" default — because a selector
 * cannot carry a media condition. That duplication is a standing divergence risk: the neutral dark
 * payload has been copy-pasted since C1, and G11 would have made it worse by adding a third
 * copy-paste site for the dark accent. Instead the dark accent assigns from `--mds-_accent-*`
 * private customs defined once, and these tests pin both halves.
 */

const here = (p: string) => fileURLToPath(new URL(p, import.meta.url));
const css = readFileSync(here('../theme-overrides.css'), 'utf8');
const primitives = JSON.parse(readFileSync(here('../../../tokens/primitive.json'), 'utf8')) as
    Record<string, Record<string, { value: string }>>;

/** Strip comments/whitespace so only the declarations are compared. */
function normalize(block: string): string[] {
    return block
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split(';')
        .map((line) => line.trim().replace(/\s+/g, ' '))
        .filter(Boolean)
        .sort();
}

function block(selectorPattern: string): string[] {
    const match = css.match(new RegExp(`${selectorPattern}\\s*\\{([^}]*)\\}`));
    expect(match, `no block matching /${selectorPattern}/`).not.toBeNull();
    return normalize(match![1]);
}

describe('dark-mode duplication', () => {
    it('keeps the explicit and prefers-color-scheme neutral blocks identical', () => {
        const explicit = block(`:root\\[data-theme-mode='dark'\\]`);
        const system = block(
            `:root:not\\(\\[data-theme-mode='light'\\]\\):not\\(\\[data-theme-mode='dark'\\]\\)`,
        );

        expect(explicit.length).toBeGreaterThan(20);
        expect(system).toEqual(explicit);
    });

    it('keeps the explicit and prefers-color-scheme dark-ACCENT blocks identical', () => {
        const explicit = block(`:root\\[data-theme-mode='dark'\\]\\[data-accent='teal'\\]`);
        const system = block(
            `:root:not\\(\\[data-theme-mode='light'\\]\\):not\\(\\[data-theme-mode='dark'\\]\\)\\[data-accent='teal'\\]`,
        );

        expect(explicit).toHaveLength(6);
        expect(system).toEqual(explicit);
    });

    it('drives both dark-accent blocks from the shared private customs, not literals', () => {
        // If a future edit inlines a colour here, the two blocks can silently diverge again.
        const explicit = block(`:root\\[data-theme-mode='dark'\\]\\[data-accent='teal'\\]`);
        for (const declaration of explicit) {
            expect(declaration).toMatch(/var\(--mds-_accent-teal-dark-/);
        }
    });
});

describe('teal accent', () => {
    it('references only teal primitives that actually exist', () => {
        const referenced = [...css.matchAll(/var\(\s*--mds-accent-teal-(\w+)\s*\)/g)].map((m) => m[1]);
        expect(referenced.length).toBeGreaterThan(0);

        for (const step of new Set(referenced)) {
            expect(
                primitives['accent-teal'][step],
                `--mds-accent-teal-${step} is referenced but not defined in primitive.json`,
            ).toBeDefined();
        }
    });

    it('overrides the full primary-action surface in light, not a partial set', () => {
        // The C2 stub set only bg / bg-hover / fg / focus-ring, leaving -bg-active and -tint blue.
        const light = block(`:root\\[data-accent='teal'\\]`);
        const properties = light.map((d) => d.split(':')[0].trim());

        expect(properties).toEqual(
            expect.arrayContaining([
                '--mds-color-action-primary-bg',
                '--mds-color-action-primary-bg-hover',
                '--mds-color-action-primary-bg-active',
                '--mds-color-action-primary-fg',
                '--mds-color-action-primary-tint',
                '--mds-color-focus-ring',
            ]),
        );
    });

    it('never remaps a neutral or semantic-status scale (§2.2 accent/semantic separation)', () => {
        const light = block(`:root\\[data-accent='teal'\\]`);
        const dark = block(`:root\\[data-theme-mode='dark'\\]\\[data-accent='teal'\\]`);

        for (const declaration of [...light, ...dark]) {
            const property = declaration.split(':')[0].trim();
            expect(property).not.toMatch(/--mds-color-(status|text|bg|border|input)-/);
        }
    });
});

/**
 * Increment H21d1 — the guard that would have caught a real WCAG 1.4.3 failure sitting in this file.
 *
 * Every primary-action FILL carries `--mds-color-text-on-primary` (white in dark) on top of it, so its
 * contrast is a property of the token, not of any one component. The dark block lightened on hover and
 * active — primary-400 at 3.96:1 and primary-300 at 2.52:1 against white — which is the opposite of what a
 * dark ground needs and the opposite of what the light block does. It survived because nothing checked:
 * the teal accent had per-token verification in a comment, the default accent had none, and a transition
 * race in `builder-axe`'s local `forceTheme` meant axe was sampling those buttons mid-flip rather than
 * settled. A comment is not a guard, which is why this is a test.
 */
describe('primary-action fills carry their own text', () => {
    /** #RRGGBB → relative luminance (WCAG 2.x). */
    function luminance(hex: string): number {
        const channel = (v: number): number => {
            const c = v / 255;
            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        };
        const n = hex.replace('#', '');
        const [r, g, b] = [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16));
        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    }

    function contrast(a: string, b: string): number {
        const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
        return (hi + 0.05) / (lo + 0.05);
    }

    /** `var(--mds-primary-600)` → the hex primitive.json defines for it. */
    function resolve(declaration: string): string | null {
        const ref = declaration.match(/var\(--mds-([a-z-]+?)-(\d+)\)/);
        if (!ref) return null;
        const [, scale, step] = ref;
        const family = scale === 'accent-teal' ? primitives['accent-teal'] : primitives[scale];

        return family?.[step]?.value ?? null;
    }

    const DARK_BLOCKS = [
        `:root\\[data-theme-mode='dark'\\]`,
        `:root:not\\(\\[data-theme-mode='light'\\]\\):not\\(\\[data-theme-mode='dark'\\]\\)`,
    ];

    it.each(DARK_BLOCKS)('keeps white legible on every primary fill in %s', (selector) => {
        const declarations = block(selector).filter((d) =>
            d.startsWith('--mds-color-action-primary-bg'),
        );

        // Anti-vacuity: a selector typo or a renamed token would otherwise assert over an empty list.
        expect(declarations.length).toBeGreaterThanOrEqual(3);

        for (const declaration of declarations) {
            const fill = resolve(declaration);
            expect(fill, `${declaration} does not resolve to a primitive`).not.toBeNull();
            expect(
                contrast('#ffffff', fill!),
                `${declaration} → ${fill} is below 4.5:1 against white`,
            ).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('pins the dark accent fills too, which is where the verification already existed', () => {
        const teal = block(`:root\\[data-accent='teal'\\]`).filter((d) =>
            d.startsWith('--mds-color-action-primary-bg'),
        );
        expect(teal.length).toBeGreaterThanOrEqual(3);

        for (const declaration of teal) {
            const fill = resolve(declaration);
            if (fill === null) continue; // an alias to a private custom is covered by the dark block above
            expect(contrast('#ffffff', fill), `${declaration} → ${fill}`).toBeGreaterThanOrEqual(4.5);
        }
    });
});

describe('dyslexia font', () => {
    it('re-points only the body family alias, never display or mono', () => {
        const rule = block(`:root\\[data-dyslexia-font='true'\\]`);

        expect(rule).toHaveLength(1);
        expect(rule[0]).toMatch(/^--mds-font-family-body:/);
        expect(rule[0]).toContain('OpenDyslexic');
        // Falls back to the default stack if the face fails to load.
        expect(rule[0]).toContain('var(--mds-font-family-body-default)');
    });
});
