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
