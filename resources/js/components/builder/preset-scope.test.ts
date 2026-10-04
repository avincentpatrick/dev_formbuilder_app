import { describe, expect, it } from 'vitest';
import type { ThemePresetOption } from '@/components/forms/types';
import { PRESET_PREVIEW_ATTR, presetScopeCss } from './preset-scope';

/**
 * M131 (`R-6017d6d8`) — the builder preview's scoped copy of a preset: the partial's three blocks, under the
 * preview's own attribute, and a member's dyslexia-friendly font still winning over a preset's body face.
 */

const TOKENS = {
    light: { bg: '#4A5568', bg_hover: '#3C4759', bg_active: '#2C3648', fg: '#4A5568', tint: '#F0F5FE', ring: '#3C4759' },
    dark: { bg: '#545F73', bg_hover: '#3E485B', bg_active: '#2F3A4B', fg: '#8793A8', tint: '#364052', ring: '#8793A8' },
};

function preset(lines: Record<string, string>): ThemePresetOption {
    return { value: 'x', label: 'X', description: '', font: '', radius: '', tokens: TOKENS, lines };
}

describe('presetScopeCss', () => {
    it('paints nothing for the workspace brand', () => {
        expect(presetScopeCss(null)).toBe('');
    });

    it('scopes the light roles and the documented lines to the preview, and the dark roles to both dark selectors', () => {
        const css = presetScopeCss(preset({ '--mds-radius-xl': '12px' }));
        const scope = `[${PRESET_PREVIEW_ATTR}]`;

        expect(css).toContain(`${scope} { --mds-color-action-primary-bg: #4A5568;`);
        expect(css).toContain('--mds-color-focus-ring: #3C4759; --mds-radius-xl: 12px; }');
        expect(css).toContain(`:root[data-theme-mode='dark'] ${scope} { --mds-color-action-primary-bg: #545F73;`);
        expect(css).toContain(`:root:not([data-theme-mode='light']):not([data-theme-mode='dark']) ${scope} { --mds-color-action-primary-bg: #545F73;`);
        // Nothing unscoped: every rule names the preview, so the admin shell around it is untouched.
        expect(css.split('\n').every((rule) => rule.includes(scope))).toBe(true);
    });

    it('re-declares the body alias for a preset body face, but never over a member who chose the dyslexia font', () => {
        const css = presetScopeCss(preset({ '--mds-font-family-body-default': 'Seravek, Ubuntu, sans-serif' }));

        expect(css).toContain("--mds-font-family-body-default: Seravek, Ubuntu, sans-serif;");
        expect(css).toContain(
            `:root:not([data-dyslexia-font='true']) [${PRESET_PREVIEW_ATTR}] { --mds-font-family-body: var(--mds-font-family-body-default); }`,
        );
        // The alias is never re-declared unconditionally — that would take the preference away.
        const unconditional = css.split('\n').filter((rule) => rule.includes('--mds-font-family-body:') && !rule.includes('data-dyslexia-font'));
        expect(unconditional).toEqual([]);
    });

    it('leaves the body alias alone when the preset sets no body face', () => {
        expect(presetScopeCss(preset({ '--mds-font-family-display': 'Charter, serif' }))).not.toContain('--mds-font-family-body:');
    });
});
