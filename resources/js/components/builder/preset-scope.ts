/**
 * A form's preset theme, scoped to the builder preview (M131, `R-6017d6d8`, `D65` = A).
 *
 * The guest page paints a preset at `:root` through the shared Blade partial. The preview lives INSIDE the admin
 * app, where `:root` belongs to the member's own appearance, so the same declarations are scoped to the preview's
 * root element instead — in the partial's three blocks, so light, forced dark and system dark resolve exactly as
 * they do for a respondent, with no JavaScript guessing which one is showing.
 *
 * ⚠️ TWO THINGS DIFFER FROM THE PARTIAL, AND BOTH ARE INHERITANCE, NOT TASTE.
 *   1. `--mds-font-family-body` is an alias of `-body-default` declared at `:root`, and a custom property's
 *      `var()` resolves where it is DECLARED — so a child inherits the already-resolved stack, and overriding
 *      `-body-default` on the preview alone would change nothing. The alias is re-declared here beside it.
 *   2. …and only when the member has NOT asked for the dyslexia-friendly font. Their `data-dyslexia-font`
 *      preference owns `--mds-font-family-body` at `:root`; re-declaring the alias inside the preview would
 *      take it from them. A preset's body face never beats a member's accessibility setting.
 *
 * Every value is server-transmitted from `FormThemePreset` (system font stacks and pixel radii under a
 * character whitelist the server's test enforces), so this builds no CSS from anything an author typed.
 */
import type { ThemePresetOption } from '@/components/forms/types';

/** The attribute on the preview's root that every block below is scoped to. */
export const PRESET_PREVIEW_ATTR = 'data-form-theme-preview';

/** The six colour roles, in the partial's order, onto the properties they repaint (ADR-0014 §D7). */
const ROLE_PROPERTIES: ReadonlyArray<readonly [keyof ThemePresetOption['tokens']['light'], string]> = [
    ['bg', '--mds-color-action-primary-bg'],
    ['bg_hover', '--mds-color-action-primary-bg-hover'],
    ['bg_active', '--mds-color-action-primary-bg-active'],
    ['fg', '--mds-color-action-primary-fg'],
    ['tint', '--mds-color-action-primary-tint'],
    ['ring', '--mds-color-focus-ring'],
];

function colours(tokens: ThemePresetOption['tokens']['light']): string {
    return ROLE_PROPERTIES.map(([role, property]) => `${property}: ${tokens[role]};`).join(' ');
}

/** The scoped CSS for a preset, or '' for the workspace's own brand (null), which the admin shell already paints. */
export function presetScopeCss(preset: ThemePresetOption | null): string {
    if (preset === null) return '';

    const scope = `[${PRESET_PREVIEW_ATTR}]`;
    const lines = Object.entries(preset.lines)
        .map(([property, value]) => `${property}: ${value};`)
        .join(' ');

    const blocks = [
        `${scope} { ${colours(preset.tokens.light)}${lines === '' ? '' : ` ${lines}`} }`,
        `:root[data-theme-mode='dark'] ${scope} { ${colours(preset.tokens.dark)} }`,
        `@media (prefers-color-scheme: dark) { :root:not([data-theme-mode='light']):not([data-theme-mode='dark']) ${scope} { ${colours(preset.tokens.dark)} } }`,
    ];

    if ('--mds-font-family-body-default' in preset.lines) {
        blocks.push(`:root:not([data-dyslexia-font='true']) ${scope} { --mds-font-family-body: var(--mds-font-family-body-default); }`);
    }

    return blocks.join('\n');
}
