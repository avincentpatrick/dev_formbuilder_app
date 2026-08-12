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

    /**
     * H24b1 — the same separation argument, extended to the chart scale, which the assertion above
     * would NOT have caught: `--mds-chart-series-3` matches none of the five `--mds-color-*` families
     * it tests for, so an accent rule could silently recolour a data series.
     *
     * ADR-0011 §D11 states the rule this pins: a data series encodes MEANING, so two colleagues
     * looking at one screenshot must see the same series in the same colour. That is the same reason
     * Moss and Brass are excluded from the accent whitelist, applied one layer further out — a
     * personal preference may repaint the primary-action hue, never the data.
     */
    it('never remaps a chart series (ADR-0011 §D11 — a series encodes meaning, not preference)', () => {
        const light = block(`:root\\[data-accent='teal'\\]`);
        const dark = block(`:root\\[data-theme-mode='dark'\\]\\[data-accent='teal'\\]`);

        for (const declaration of [...light, ...dark]) {
            expect(declaration.split(':')[0].trim()).not.toMatch(/^--mds-chart-/);
        }
    });
});

/**
 * H24b1 — the chart scale's own contrast guard.
 *
 * A chart mark is a "meaningful graphical object" under WCAG 1.4.11 and takes 3:1 against the surface
 * it sits on, which is #FFFFFF in light and #123350 in dark. The five-token cap and the mandatory dark
 * re-point both fall out of that arithmetic (ADR-0011 §D11), so the arithmetic is asserted rather than
 * recorded in a comment — the H21d1 lesson, applied before the defect rather than after it.
 *
 * Gridlines are deliberately EXEMPT and deliberately below 3:1: they are decorative scaffolding, and a
 * gridline that competes with the data is a defect in the other direction. That is asserted too, so a
 * later "fix" that darkens them is caught.
 */
describe('chart series contrast (ADR-0011 §D11)', () => {
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

    const chart = JSON.parse(readFileSync(here('../../../tokens/chart.json'), 'utf8')) as {
        chart: Record<string, { value: string }>;
    };

    /** `--mds-_chart-dark-3: #37b98c;` → `#37b98c`, read from the single definition site. */
    function darkSeries(index: number): string {
        const match = css.match(new RegExp(`--mds-_chart-dark-${index}:\\s*(#[0-9a-fA-F]{6})`));
        expect(match, `--mds-_chart-dark-${index} is not defined in theme-overrides.css`).not.toBeNull();

        return match![1];
    }

    /**
     * The dark neutral ramp lives in the CSS, not in primitive.json — primitive.json holds the LIGHT
     * column. Reading `neutral-100` out of primitive.json here would silently measure against #E8EAE4
     * and pass everything, which is the whole hazard this suite exists for.
     */
    function darkNeutral(step: number): string {
        const declaration = block(`:root\\[data-theme-mode='dark'\\]`).find((d) =>
            d.startsWith(`--mds-neutral-${step}:`),
        );
        expect(declaration, `--mds-neutral-${step} is not re-pointed in the dark block`).toBeDefined();

        return declaration!.split(':')[1].trim();
    }

    const LIGHT_SURFACE = primitives['neutral']['0'].value; // --mds-color-bg-surface → --mds-neutral-0
    const DARK_SURFACE = darkNeutral(100); // the dark flip re-points bg-surface to neutral-100

    it.each([1, 2, 3, 4, 5])('keeps light series %i at 3:1 against the light surface', (i) => {
        const hex = chart.chart[`series-${i}`].value;
        expect(hex, `series-${i} must be a literal hex, not an alias`).toMatch(/^#[0-9a-fA-F]{6}$/);
        expect(contrast(hex, LIGHT_SURFACE), `series-${i} (${hex}) vs ${LIGHT_SURFACE}`).toBeGreaterThanOrEqual(3);
    });

    it.each([1, 2, 3, 4, 5])('keeps dark series %i at 3:1 against the dark surface', (i) => {
        const hex = darkSeries(i);
        expect(contrast(hex, DARK_SURFACE), `_chart-dark-${i} (${hex}) vs ${DARK_SURFACE}`).toBeGreaterThanOrEqual(3);
    });

    it('caps the categorical scale at five (§D11 — five is derived, not chosen)', () => {
        const series = Object.keys(chart.chart).filter((k) => k.startsWith('series-'));
        expect(series).toHaveLength(5);
    });

    it('keeps gridlines BELOW 3:1 in both themes — they are scaffolding, not data', () => {
        // `{neutral.200}`, so it flips with the ramp; each end is measured at its own surface.
        expect(chart.chart['grid'].value).toBe('{neutral.200}');
        expect(contrast(primitives['neutral']['200'].value, LIGHT_SURFACE)).toBeLessThan(3);
        expect(contrast(darkNeutral(200), DARK_SURFACE)).toBeLessThan(3);
    });

    it('keeps the axis/tick-label colour at 4.5:1 in both themes — tick labels are TEXT', () => {
        expect(chart.chart['axis'].value).toBe('{neutral.600}');
        expect(contrast(primitives['neutral']['600'].value, LIGHT_SURFACE)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(darkNeutral(600), DARK_SURFACE)).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps the Other bucket at 3:1 in both themes without recycling a series hue', () => {
        // §D11: "Other" is painted a NEUTRAL so it never reads as a peer category. Aliasing the
        // neutral ramp is what makes it correct in both themes with no re-point of its own.
        expect(chart.chart['other'].value).toBe('{neutral.500}');
        expect(contrast(primitives['neutral']['500'].value, LIGHT_SURFACE)).toBeGreaterThanOrEqual(3);
        expect(contrast(darkNeutral(500), DARK_SURFACE)).toBeGreaterThanOrEqual(3);
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

/**
 * JR1 — the neutral ramp must be MONOTONIC in luminance, in both themes.
 *
 * This is the guard that would have caught what a hand-check could not. JR1's derived dark `neutral-0`
 * landed **0.00016 luminance above `neutral-50`** — invisible to the eye, invisible in a diff of hexes
 * (`#0d1322` next to `#0f131c` looks obviously darker; it is not), and a real inversion of the ramp's
 * one structural promise: low steps dark, high steps pale.
 *
 * It is not cosmetic. In dark, `--mds-color-input-bg` is `neutral-0`, so an input would have rendered a
 * fraction LIGHTER than the canvas behind it rather than sunken into it — a depth cue pointing the wrong
 * way, at a magnitude no screenshot review would ever flag.
 *
 * The ramp is the substrate every semantic alias resolves through, so an inversion anywhere in it can
 * surface as an arbitrary component looking subtly wrong three increments later. Ordering is the
 * cheapest possible invariant to assert and the most expensive to debug from the symptom.
 */
describe('neutral ramp ordering', () => {
    function luminance(hex: string): number {
        const channel = (v: number): number => {
            const c = v / 255;
            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        };
        const n = hex.replace('#', '');
        const [r, g, b] = [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16));
        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    }

    /** Every step the ramp defines, in scale order — read from the source rather than hard-coded. */
    const STEPS = Object.keys(primitives['neutral']).map(Number).sort((a, b) => a - b);

    function darkNeutral(step: number): string {
        const declaration = block(`:root\\[data-theme-mode='dark'\\]`).find((d) =>
            d.startsWith(`--mds-neutral-${step}:`),
        );
        expect(declaration, `--mds-neutral-${step} is not re-pointed in the dark block`).toBeDefined();

        return declaration!.split(':')[1].trim();
    }

    it('covers every step in both columns (anti-vacuity)', () => {
        // A renamed or dropped step would otherwise make the two assertions below iterate over less
        // than the ramp and still pass.
        expect(STEPS.length).toBeGreaterThanOrEqual(12);
        for (const step of STEPS) expect(darkNeutral(step)).toMatch(/^#[0-9a-fA-F]{6}$/);
    });

    it('gets strictly DARKER as the step rises, in light', () => {
        const luminances = STEPS.map((s) => luminance(primitives['neutral'][String(s)].value));

        for (let i = 1; i < luminances.length; i++) {
            expect(
                luminances[i],
                `light neutral-${STEPS[i]} is not darker than neutral-${STEPS[i - 1]}`,
            ).toBeLessThan(luminances[i - 1]);
        }
    });

    it('gets strictly PALER as the step rises, in dark', () => {
        const luminances = STEPS.map((s) => luminance(darkNeutral(s)));

        for (let i = 1; i < luminances.length; i++) {
            expect(
                luminances[i],
                `dark neutral-${STEPS[i]} is not paler than neutral-${STEPS[i - 1]}`,
            ).toBeGreaterThan(luminances[i - 1]);
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
