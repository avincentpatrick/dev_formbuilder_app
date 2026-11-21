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
 * M243 (`R-1da063f4`): on a multi-select each choice can be an "only answer", stored as `exclusive: true` and removed, not
 * saved false, when turned off — so a choice that never had it saves exactly as before.
 */
describe('ChoicesEditor — Only answer (M243)', () => {
    const options = [
        { value: 'a', label: 'A' },
        { value: 'noview', label: 'No view', exclusive: true },
    ];

    it('offers it only where the question takes it', () => {
        expect(mount(ChoicesEditor, { props: { options } }).find('[data-choice-exclusive]').exists()).toBe(false);
        expect(mount(ChoicesEditor, { props: { options, allowExclusive: true } }).findAll('[data-choice-exclusive]')).toHaveLength(2);
    });

    it('marks a choice, and unmarks one by removing the key', async () => {
        const wrapper = mount(ChoicesEditor, { props: { options, allowExclusive: true } });
        const boxes = wrapper.findAll('[data-choice-exclusive] input[type="checkbox"]');

        await boxes[0].setValue(true);
        await boxes[1].setValue(false);

        expect(wrapper.emitted('update:options')).toEqual([
            [[{ value: 'a', label: 'A', exclusive: true }, options[1]]],
            [[options[0], { value: 'noview', label: 'No view' }]],
        ]);
    });
});
