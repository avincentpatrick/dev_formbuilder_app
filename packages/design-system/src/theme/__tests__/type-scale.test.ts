import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Guard for the §2.9 text-size scale.
 *
 * `theme-overrides.css` hand-authors 44 declarations (11 type roles × font-size + line-height ×
 * two levels). A single typo there is invisible in review and would ship a broken line box to the
 * users who most need the feature. This test re-derives every value from `tokens/typography.json`
 * and asserts the CSS matches, so the table in DSR §2.9, the CSS, and the token source cannot drift.
 *
 * The rule under test (see the CSS comment for the rationale):
 *   fontSize   = round(baseFontSize × factor)
 *   lineHeight = round(fontSize × the role's ORIGINAL ratio)   ← derived, NOT independently rounded
 *
 * Deriving the line-height rather than rounding it separately is what keeps the ratios stable:
 * independent rounding compresses `caption` at large from 1.333 → 1.286 and `body-sm`/`label` from
 * 1.385 → 1.333.
 */

const here = (p: string) => fileURLToPath(new URL(p, import.meta.url));

const typography = JSON.parse(readFileSync(here('../../../tokens/typography.json'), 'utf8')) as {
    type: Record<string, { 'font-size': { value: string }; 'line-height': { value: string } }>;
};
const css = readFileSync(here('../theme-overrides.css'), 'utf8');

const LEVELS = [
    { attribute: 'large', factor: 1.125 },
    { attribute: 'extra_large', factor: 1.25 },
] as const;

const px = (value: string): number => Number.parseInt(value, 10);

/** Extract the declaration block for `:root[data-font-size='<level>']`. */
function blockFor(level: string): string {
    const match = css.match(new RegExp(`:root\\[data-font-size='${level}'\\]\\s*\\{([^}]*)\\}`));
    expect(match, `no :root[data-font-size='${level}'] block found`).not.toBeNull();
    return match![1];
}

function declaredPx(block: string, property: string): number | null {
    const match = block.match(new RegExp(`${property}\\s*:\\s*(\\d+)px`));
    return match ? Number(match[1]) : null;
}

const roles = Object.keys(typography.type);

describe('§2.9 text-size scale', () => {
    it('covers all 11 type roles', () => {
        expect(roles).toHaveLength(11);
    });

    describe.each(LEVELS)('data-font-size="$attribute" (×$factor)', ({ attribute, factor }) => {
        const block = blockFor(attribute);

        it.each(roles)('%s scales proportionally and keeps its line-height ratio', (role) => {
            const baseFontSize = px(typography.type[role]['font-size'].value);
            const baseLineHeight = px(typography.type[role]['line-height'].value);
            const baseRatio = baseLineHeight / baseFontSize;

            const expectedFontSize = Math.round(baseFontSize * factor);
            const expectedLineHeight = Math.round(expectedFontSize * baseRatio);

            const actualFontSize = declaredPx(block, `--mds-type-${role}-font-size`);
            const actualLineHeight = declaredPx(block, `--mds-type-${role}-line-height`);

            // Both properties must be overridden — overriding size without line-height is the
            // original DSR §2.9 mistake this scale exists to correct.
            expect(actualFontSize, `--mds-type-${role}-font-size missing`).not.toBeNull();
            expect(actualLineHeight, `--mds-type-${role}-line-height missing`).not.toBeNull();

            expect(actualFontSize).toBe(expectedFontSize);
            expect(actualLineHeight).toBe(expectedLineHeight);

            // The point of the whole exercise: legible leading at every scale.
            const actualRatio = actualLineHeight! / actualFontSize!;
            expect(Math.abs(actualRatio - baseRatio) / baseRatio).toBeLessThan(0.02);
            expect(actualRatio).toBeGreaterThanOrEqual(1.2);
        });
    });

    it('scales `code` too — mono is size-scaled even though its FACE is never swapped', () => {
        // Deliberate asymmetry with the dyslexia toggle; see the CSS comment. Pinned so a future
        // reader does not "fix" the apparent inconsistency by dropping code from the scale.
        expect(declaredPx(blockFor('extra_large'), '--mds-type-code-font-size')).toBe(16);
    });

    it('has no data-font-size="standard" block — standard is the ABSENCE of the attribute', () => {
        expect(css).not.toContain("data-font-size='standard'");
    });
});
