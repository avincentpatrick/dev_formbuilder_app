import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ChoicesEditor from './ChoicesEditor.vue';

/**
 * M177 (`R-0b7db001`, `D140`): Yes/No left the palette because a Single choice with the choices Yes and No already covers
 * it — so an empty choice list offers exactly those two, with the values the XLSForm export has always written (`yes`,
 * `no`), in one click.
 */
describe('ChoicesEditor — the Yes and No preset (M177)', () => {
    it('offers Yes and No on an empty list, and adds both in one change', async () => {
        const wrapper = mount(ChoicesEditor, { props: { options: [] } });

        await wrapper.get('[data-choices-yes-no]').trigger('click');

        expect(wrapper.emitted('update:options')).toEqual([[[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }]]]);
    });

    it('offers nothing once the list holds a choice, so it never replaces an author’s own', () => {
        const wrapper = mount(ChoicesEditor, { props: { options: [{ value: 'a', label: 'A' }] } });

        expect(wrapper.find('[data-choices-yes-no]').exists()).toBe(false);
    });

    it('is disabled with the rest of the editor', () => {
        const wrapper = mount(ChoicesEditor, { props: { options: [], disabled: true } });

        expect(wrapper.get('[data-choices-yes-no]').attributes('disabled')).toBeDefined();
    });
});

/**
 * M249 (`dropdown-placeholder`, `D233`): a dropdown's choices take a short label — what the closed box shows while that
 * choice is chosen. The column shows only where the server says the type takes one, and a blank removes the key with any
 * imported translations of it, so a choice never given one saves exactly as before.
 */
describe('ChoicesEditor — a short label per choice (M249)', () => {
    it('offers the column only when allowed', () => {
        const off = mount(ChoicesEditor, { props: { options: [{ value: 'a', label: 'A' }] } });
        expect(off.find('[data-choice-short-label]').exists()).toBe(false);
        off.unmount();

        const on = mount(ChoicesEditor, { props: { options: [{ value: 'a', label: 'A' }], allowShortLabel: true } });
        expect(on.get('[data-choice-short-label]').attributes('aria-label')).toBe('Short form of option 1');
        expect(on.text()).toContain('Short label');
        on.unmount();
    });

    it('sets a short label, and removes it with its translations when emptied', async () => {
        const wrapper = mount(ChoicesEditor, {
            props: {
                options: [{ value: 'include', label: 'Include. As it stands.', short_label: 'Incl', short_label_translations: { fil: 'Isama' } }],
                allowShortLabel: true,
            },
        });

        await wrapper.get('[data-choice-short-label]').setValue('Include');
        await wrapper.get('[data-choice-short-label]').setValue('');

        const emitted = wrapper.emitted('update:options') as unknown[][];
        expect(emitted[0][0]).toEqual([{ value: 'include', label: 'Include. As it stands.', short_label: 'Include', short_label_translations: { fil: 'Isama' } }]);
        expect(emitted[1][0]).toEqual([{ value: 'include', label: 'Include. As it stands.' }]);
        wrapper.unmount();
    });
});
