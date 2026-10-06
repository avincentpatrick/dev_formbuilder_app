import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import BuilderCanvas from './BuilderCanvas.vue';
import { pageProps, serverField, serverSection } from './builder-store-fixtures';
import { useBuilderStore } from './useBuilderStore';

/**
 * M139 (`R-598b9100`) — an empty section in Structure says a question can be dragged in, and offers to add one there.
 * The drag always worked (measured by `M139`'s real-browser probe); the words said only "No fields in this section yet."
 */
function canvas() {
    const store = useBuilderStore(
        pageProps({
            sections: [
                serverSection({ id: 's1', key: 'household', label: 'Household', sequence: 1 }),
                serverSection({ id: 's2', key: 'visit', label: 'Visit', sequence: 2 }),
            ],
            fields: [serverField({ id: 'f1', key: 'name', label: 'Name', form_section_id: 's1' })],
        }),
    );

    return { store, wrapper: mount(BuilderCanvas, { props: { store, fieldTypeLabels: { short_text: 'Text' } } }) };
}

describe('BuilderCanvas — an empty section (M139)', () => {
    it('says a question can be dragged in, and only where a section has none', () => {
        const { wrapper } = canvas();
        const empties = wrapper.findAll('[data-section-empty]');

        expect(empties).toHaveLength(1);
        expect(empties[0].text()).toContain('Drag one in by its handle, or add one.');
        // Inside the section's drop zone, so a drop on the words lands in the section.
        expect(empties[0].element.closest('[data-drop-group]')?.getAttribute('data-drop-group')).toBe('s2');
    });

    it('hands the builder the section to add a question to', async () => {
        const { store, wrapper } = canvas();

        await wrapper.find('[data-section-empty] button').trigger('click');

        const visit = store.sections.value.find((s) => s.key === 'visit')!;
        expect(wrapper.emitted('add-question')).toEqual([[visit.uid]]);
    });
});
