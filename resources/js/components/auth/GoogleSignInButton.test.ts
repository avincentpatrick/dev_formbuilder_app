import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import GoogleSignInButton from './GoogleSignInButton.vue';

/**
 * "Continue with Google" (Increment J3c2 — ADR-0019).
 *
 * ⚠️ THE FIRST CASE IS A CANARY FOR THE E2E SUITE, NOT A COPY ASSERTION, AND THAT IS WHY IT IS HERE.
 * `tests/e2e/global-setup.ts` signs in twice before any spec runs, locating the submit control with
 * `getByRole('button', { name: 'Sign in' })` — NOT exact, so it matches on substring. A control whose
 * accessible name merely CONTAINS "Sign in" makes that a strict-mode violation, which fails global setup
 * and takes every spec in the suite down with none executed. That failure costs a full CI cycle to see;
 * this costs five milliseconds. `AuthLayout.vue` records the same constraint for the marketing panel.
 *
 * ⚠️ AND IT ASSERTS AN ANCHOR RATHER THAN A BUTTON. `MdsButton`'s `as="a"` exists for exactly this — an
 * action that navigates to another origin has to be a link — and it is also the belt to the label's
 * braces: an anchor's implicit role is `link`, so it cannot match a `getByRole('button', …)` query even
 * if somebody later rewords the text.
 */

function render(): VueWrapper {
    return mount(GoogleSignInButton);
}

describe('the Google sign-in control', () => {
    it('is a link named exactly "Continue with Google", and never says "Sign in"', () => {
        const wrapper = render();

        const anchor = wrapper.get('a');

        expect(anchor.attributes('href')).toBe('/auth/google/redirect');
        expect(anchor.text().replace(/\s+/g, ' ').trim()).toBe('Continue with Google');

        // The canary. `getByRole('button', { name: 'Sign in' })` in global setup is a SUBSTRING match, so
        // "Sign in with Google" would be a strict-mode violation there rather than a wording preference.
        expect(anchor.text()).not.toMatch(/sign in/i);

        // An anchor, so its role is `link`. Nothing here may render a <button>.
        expect(wrapper.find('button').exists()).toBe(false);

        wrapper.unmount();
    });

    it('hides the vendor mark from assistive technology', () => {
        const wrapper = render();

        // The accessible name comes from the button's text alone; a mark that announced itself would make
        // the control read as "Google Continue with Google".
        const mark = wrapper.get('svg');
        expect(mark.attributes('aria-hidden')).toBe('true');
        expect(mark.attributes('focusable')).toBe('false');

        wrapper.unmount();
    });

    it('keeps the divider out of the accessibility tree', () => {
        const wrapper = render();

        // A visual separator only. A screen reader announcing "or" between two controls it already reads
        // in order adds nothing, and it must never become part of the link's accessible name.
        const divider = wrapper.get('.auth-social__divider');
        expect(divider.attributes('aria-hidden')).toBe('true');
        expect(wrapper.get('a').text()).not.toContain('or');

        wrapper.unmount();
    });

    it('carries the vendor mark as local markup, never as an MdsIcon', () => {
        const wrapper = render();

        // `icons.ts` is hand-authored single-path line art rendered `fill: none; stroke: currentColor`,
        // and refuses vendor marks by rule — so `IconName` must never gain a `google` key. The four fills
        // below are what that rule costs and what exceptions-log #15 records: raw brand hexes, outside the
        // token system, deliberately identical in light and dark because they are Google's and not ours.
        const fills = wrapper.findAll('svg path').map((path) => path.attributes('fill'));

        expect(fills).toEqual(['#4285F4', '#34A853', '#FBBC05', '#EA4335']);
        expect(wrapper.html()).not.toContain('mds-icon');

        wrapper.unmount();
    });
});
