/**
 * The Glossary button (M245, `R-d0bbb7bb`): present only on a form that has a glossary, and listing every term with
 * its meaning in a dialog.
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { computed } from 'vue';
import GlossaryButton from './GlossaryButton.vue';
import { ContentGlossaryKey } from './note-content';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the Glossary button', () => {
    it('is not there on a form with no glossary', () => {
        expect(mount(GlossaryButton).find('button').exists()).toBe(false);
        expect(
            mount(GlossaryButton, { global: { provide: { [ContentGlossaryKey as symbol]: computed(() => []) } } })
                .find('button')
                .exists(),
        ).toBe(false);
    });

    it('lists every term and its meaning, in the order given', async () => {
        const glossary = computed(() => [
            { term: 'barangay', definition: 'A village.' },
            { term: 'TWG', definition: 'The technical working group.' },
        ]);
        const wrapper = mount(GlossaryButton, { global: { provide: { [ContentGlossaryKey as symbol]: glossary } }, attachTo: document.body });

        await wrapper.get('[data-glossary-button]').trigger('click');

        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog?.querySelector('h2, [id]')?.textContent ?? dialog?.textContent).toContain('Glossary');
        expect(Array.from(dialog?.querySelectorAll('dt') ?? [], (dt) => dt.textContent)).toEqual(['barangay', 'TWG']);
        expect(Array.from(dialog?.querySelectorAll('dd') ?? [], (dd) => dd.textContent)).toEqual(['A village.', 'The technical working group.']);
        wrapper.unmount();
    });
});
