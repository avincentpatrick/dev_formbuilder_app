import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TextInput from './TextInput.vue';

/**
 * Increment J1a — the first spec for this component, added with the `type="search"` widening.
 *
 * ⚠️ WHAT THIS FILE CAN AND CANNOT PROVE. `input[type=search]`'s implicit role is `searchbox`, and that
 * mapping is a BROWSER property: neither happy-dom nor @vue/test-utils computes implicit ARIA roles, so
 * nothing here can assert it. The cases below assert the attribute that PRODUCES the role, and the locator
 * consequence is recorded in TextInput.vue's docblock instead. The real gate for the rendered role is
 * Playwright's `getByRole('searchbox')`, which J1b's nav field is located by.
 *
 * The rest is the zero-blast-radius claim, which is the one that would be expensive to get wrong: this
 * primitive is rendered by every form in the app, so "the default did not move" needs a test that fails
 * rather than a sentence in a PR body.
 */
describe('MdsTextInput — type', () => {
    it('defaults to text, so the widening changes no existing call site', () => {
        const wrapper = mount(TextInput);

        expect(wrapper.get('input').attributes('type')).toBe('text');

        wrapper.unmount();
    });

    it('renders type="search", which is what produces the searchbox role', () => {
        // Mutation: hardcode `type="text"` in the template and this reddens. Without it the union widening
        // is a type-level change with no runtime proof at all.
        const wrapper = mount(TextInput, { props: { type: 'search' } });

        expect(wrapper.get('input').attributes('type')).toBe('search');

        wrapper.unmount();
    });

    it('still emits update:modelValue on a search input', async () => {
        // The @input handler is type-independent, which is exactly why it is worth one case: a widening
        // that broke the binding for one type only would be invisible everywhere else.
        const wrapper = mount(TextInput, { props: { type: 'search', modelValue: '' } });

        await wrapper.get('input').setValue('clinic intake');

        expect(wrapper.emitted('update:modelValue')).toEqual([['clinic intake']]);

        wrapper.unmount();
    });
});

describe('MdsTextInput — attribute fall-through', () => {
    it('passes aria-label down to the input itself', () => {
        // `inheritAttrs` is deliberately NOT disabled here (unlike Select.vue), and several live call sites
        // depend on it. The dependents are the ones passing UNDECLARED attributes — `ChoicesEditor`,
        // `CascadingEditor`, `GridOptionList`, `ValidationEditor` and `ConditionRow` all pass `aria-label`,
        // and `TwoFactorChallenge` passes `inputmode="numeric"` + `autocomplete="one-time-code"`, which is
        // the highest-stakes one since losing `inputmode` degrades a 2FA field on mobile. (`AnalyticsFilterBar`
        // is NOT among them — it passes only declared props and a declared emit — which is worth naming
        // because it looks like a dependent and is not.)
        //
        // Adding `defineOptions({ inheritAttrs: false })` would move those onto a wrapper that does not
        // exist, silently unnaming the control. vue-tsc cannot see that; this can.
        const wrapper = mount(TextInput, {
            props: { type: 'search' },
            attrs: { 'aria-label': 'Search submissions' },
        });

        expect(wrapper.get('input').attributes('aria-label')).toBe('Search submissions');

        wrapper.unmount();
    });
});
