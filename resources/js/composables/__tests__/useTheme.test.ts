import { beforeEach, describe, expect, it } from 'vitest';
import {
    applyAccent,
    applyDyslexiaFont,
    applyFontSize,
    applyThemeMode,
} from '@/composables/useTheme';

/**
 * Pins the "default = ABSENCE of the attribute" convention (design-system-reference.md §2.9).
 *
 * The convention is what keeps the client-side optimistic apply and the server-side blade emission in
 * agreement: both must express "system / blueprint / standard / no dyslexia font" by REMOVING the
 * attribute, never by writing a value like data-font-size="standard" (for which no CSS rule exists).
 * If a setter ever wrote the default as a value instead of removing it, a user switching back to the
 * default would keep the personalized rendering until their next full page load.
 */

const html = () => document.documentElement;

beforeEach(() => {
    for (const attribute of ['data-theme-mode', 'data-accent', 'data-font-size', 'data-dyslexia-font']) {
        html().removeAttribute(attribute);
    }
});

describe('applyThemeMode', () => {
    it.each(['light', 'dark'] as const)('sets data-theme-mode for %s', (mode) => {
        applyThemeMode(mode);
        expect(html().getAttribute('data-theme-mode')).toBe(mode);
    });

    it('removes the attribute for system', () => {
        applyThemeMode('dark');
        applyThemeMode('system');
        expect(html().hasAttribute('data-theme-mode')).toBe(false);
    });
});

describe('applyAccent', () => {
    it('sets data-accent for teal', () => {
        applyAccent('teal');
        expect(html().getAttribute('data-accent')).toBe('teal');
    });

    it('removes the attribute for blueprint (the product default)', () => {
        applyAccent('teal');
        applyAccent('blueprint');
        expect(html().hasAttribute('data-accent')).toBe(false);
    });
});

describe('applyFontSize', () => {
    it.each(['large', 'extra_large'] as const)('sets data-font-size for %s', (scale) => {
        applyFontSize(scale);
        expect(html().getAttribute('data-font-size')).toBe(scale);
    });

    it('removes the attribute for standard', () => {
        applyFontSize('extra_large');
        applyFontSize('standard');
        expect(html().hasAttribute('data-font-size')).toBe(false);
    });
});

describe('applyDyslexiaFont', () => {
    it('sets data-dyslexia-font="true" when enabled', () => {
        applyDyslexiaFont(true);
        expect(html().getAttribute('data-dyslexia-font')).toBe('true');
    });

    it('removes the attribute when disabled', () => {
        applyDyslexiaFont(true);
        applyDyslexiaFont(false);
        expect(html().hasAttribute('data-dyslexia-font')).toBe(false);
    });
});

describe('axis independence', () => {
    it('never disturbs another axis', () => {
        // Each setter owns exactly one attribute — mirroring the server contract, where each control
        // PATCHes only its own field and `sometimes` rules leave the rest untouched.
        applyThemeMode('dark');
        applyAccent('teal');
        applyFontSize('large');
        applyDyslexiaFont(true);

        applyFontSize('standard');

        expect(html().getAttribute('data-theme-mode')).toBe('dark');
        expect(html().getAttribute('data-accent')).toBe('teal');
        expect(html().hasAttribute('data-font-size')).toBe(false);
        expect(html().getAttribute('data-dyslexia-font')).toBe('true');
    });
});
