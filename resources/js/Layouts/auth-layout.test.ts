import { describe, expect, it } from 'vitest';
import { containerBlock, nested, read, rule, styleOf } from '../__tests__/css-source';

/**
 * The two front doors become two halves (M174, `R-3d51f0ae`, `D122`): on a wide screen the pitch panel fills the left
 * half on a sunken surface and the sign-in card sits centred in the right half, instead of a 400px card and a 26rem
 * panel huddled in the middle of the glass; below the threshold the page is the plain card it has always been. The
 * landing page's feature grid fills the page. happy-dom lays out nothing, so the halves are measured in a real browser
 * (`tests/e2e/auth-axe.spec.ts`); this file pins the source shape that produces them.
 */

const AUTH = 'resources/js/Layouts/AuthLayout.vue';
const WELCOME = 'resources/js/Pages/Welcome.vue';

describe('sign-in and register — two halves on a wide screen', () => {
    it('lets the split variant carry no padding of its own, so the panel reaches the edges', () => {
        const body = rule(styleOf(AUTH), '.auth--split');

        expect(body).toMatch(/container-type:\s*inline-size/);
        expect(body).toMatch(/padding:\s*0/);
    });

    it('lays the frame in two equal halves from the threshold', () => {
        const block = containerBlock(styleOf(AUTH), '57em');
        const frame = nested(block, '.auth--split > .auth__frame');

        expect(frame, '@container (min-width: 57em) .auth--split > .auth__frame').not.toBeNull();
        expect(frame).toMatch(/display:\s*grid/);
        expect(frame).toMatch(/grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(0,\s*1fr\)/);
    });

    it('puts the pitch on the sunken surface with a divider, since sunken is the canvas colour in dark', () => {
        const pitch = nested(containerBlock(styleOf(AUTH), '57em'), '.auth__pitch');

        expect(pitch).toMatch(/background-color:\s*var\(--mds-color-bg-sunken\)/);
        expect(pitch).toMatch(/border-inline-end:\s*1px solid var\(--mds-color-border-default\)/);
    });

    it('keeps the panel free of any control and its headline a paragraph', () => {
        const panel = read(AUTH).match(/<aside[\s\S]*?<\/aside>/)?.[0] ?? '';

        expect(panel).toContain('<p class="auth__pitch-title">');
        expect(panel).not.toMatch(/<(a|button|MdsButton|Link)\b/);
    });
});

describe('landing page — the feature grid fills the page', () => {
    it('caps neither the page nor the grid, while the hero and the lede keep their measures', () => {
        const css = styleOf(WELCOME);

        expect(css).not.toMatch(/^\s+max-width:\s*64rem/m);
        expect(rule(css, '.landing__hero')).toMatch(/max-width:\s*44rem/);
        expect(rule(css, '.landing__lede')).toMatch(/max-width:\s*36rem/);
        expect(rule(css, '.landing__grid')).toMatch(/grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(min\(100%,\s*15rem\),\s*1fr\)\)/);
    });

    it('takes the tracking token on its eyebrow', () => {
        expect(rule(styleOf(WELCOME), '.landing__eyebrow')).toMatch(/letter-spacing:\s*var\(--mds-tracking-wide\)/);
    });
});
