import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import AddQuestionHere from './AddQuestionHere.vue';
import { PALETTE } from './builder-store-fixtures';

/**
 * M176 (`R-5354b4b9`, `D142` A) — the testers: "“+ Add question here” button not working". It moved focus to a palette
 * that was easy to miss and refused focus while a save ran. The list now opens where the button is.
 */
function mountHere() {
    return mount(AddQuestionHere, { props: { palette: PALETTE, label: 'Add a question here' }, attachTo: document.body });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('AddQuestionHere', () => {
    it('opens the palette\'s types right here, focused on the first, one entry per variant group', async () => {
        const wrapper = mountHere();
        const toggle = wrapper.get('[data-add-here-toggle]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-add-here-list]').exists()).toBe(false);

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        const list = wrapper.get('[data-add-here-list]');
        expect(toggle.attributes('aria-controls')).toBe(list.attributes('id'));
        // "Text" and "Number" once each (their other formats are a Basics setting), as the left pane lists them.
        const offered = list.findAll('[data-add-type]').map((type) => type.text());
        expect(offered.slice(0, 5)).toEqual(['Text', 'Email', 'Phone', 'Number', 'Calculated']);
        expect(offered).not.toContain('Long text');
        expect(offered).not.toContain('Decimal number');
        expect(document.activeElement).toBe(list.find('[data-add-type]').element);
    });

    it('says which type was chosen, and folds away', async () => {
        const wrapper = mountHere();
        await wrapper.get('[data-add-here-toggle]').trigger('click');

        await wrapper.get('[data-add-type="integer"]').trigger('click');

        expect(wrapper.emitted('add')).toEqual([['integer']]);
        expect(wrapper.find('[data-add-here-list]').exists()).toBe(false);
    });

    it('closes on Escape or Cancel and gives focus back to the button, choosing nothing', async () => {
        const wrapper = mountHere();
        const toggle = wrapper.get('[data-add-here-toggle]');
        await toggle.trigger('click');

        await wrapper.get('[data-add-here-list]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[data-add-here-list]').exists()).toBe(false);
        expect(document.activeElement).toBe(toggle.element);

        await toggle.trigger('click');
        await wrapper.get('[data-add-here-cancel]').trigger('click');
        expect(wrapper.find('[data-add-here-list]').exists()).toBe(false);
        expect(wrapper.emitted('add')).toBeUndefined();
    });
});
