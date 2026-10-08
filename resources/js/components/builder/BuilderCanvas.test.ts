import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BuilderCanvas from './BuilderCanvas.vue';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
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

/**
 * M150 (`R-34edf1f5`, the user's Round 2 note: "can we also use the middle section … to allow the user to edit the label
 * there already") — a question's label edited on its row: one PATCH and one undo entry per edit, never one per keystroke.
 */
describe('BuilderCanvas — a label edited in place (M150)', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    function editable() {
        const fetch = fetchMock().mockResolvedValue(jsonResponse(200, serverField({ id: 'f1', label: 'Full name', version: 'v2' })));
        const store = useBuilderStore(pageProps({ sections: [], fields: [serverField({ id: 'f1', key: 'name', label: 'Name', form_section_id: null })] }));
        const wrapper = mount(BuilderCanvas, { props: { store, fieldTypeLabels: { short_text: 'Text' } }, attachTo: document.body });
        const patches = () => requestLog(fetch).filter((r) => r.method === 'PATCH' && r.url.endsWith('/fields/f1'));

        return { store, wrapper, patches };
    }

    async function open(wrapper: ReturnType<typeof editable>['wrapper']): Promise<HTMLInputElement> {
        await wrapper.find('button[aria-label="Edit label of Name"]').trigger('click');
        return wrapper.find('input[aria-label="Question label"]').element as HTMLInputElement;
    }

    it('swaps the row for a focused input holding the label', async () => {
        const { wrapper } = editable();
        const input = await open(wrapper);

        expect(input.value).toBe('Name');
        expect(document.activeElement).toBe(input);
        expect(input.getAttribute('maxlength')).toBe('500');
        expect(wrapper.find('.canvas__field-main').exists()).toBe(false);
    });

    it('saves the trimmed label once on Enter, and gives focus back to the row', async () => {
        const { store, wrapper, patches } = editable();
        const input = await open(wrapper);

        await wrapper.find('input[aria-label="Question label"]').setValue('  Full name  ');
        // Nothing is written while typing.
        expect(patches()).toHaveLength(0);
        await wrapper.find('input[aria-label="Question label"]').trigger('keydown', { key: 'Enter' });
        // Written at once — not after the settings pane's 600ms debounce.
        await flushPromises();

        expect(patches()).toHaveLength(1);
        expect((patches()[0].body as { label: string }).label).toBe('Full name');
        expect(store.fields.value[0].label).toBe('Full name');
        expect(input.isConnected).toBe(false);
        expect(document.activeElement).toBe(wrapper.find('.canvas__field-main').element);
        expect(wrapper.find('.canvas__field-main').text()).toContain('Full name');
    });

    it('is one undo entry', async () => {
        const { store, wrapper, patches } = editable();
        await open(wrapper);
        await wrapper.find('input[aria-label="Question label"]').setValue('Full name');
        await wrapper.find('input[aria-label="Question label"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        await store.undo();
        await flushPromises();

        expect(store.fields.value[0].label).toBe('Name');
        expect(patches()).toHaveLength(2);
    });

    it('keeps the label on Escape, and writes nothing', async () => {
        const { store, wrapper, patches } = editable();
        await open(wrapper);
        await wrapper.find('input[aria-label="Question label"]').setValue('Something else');
        await wrapper.find('input[aria-label="Question label"]').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(patches()).toHaveLength(0);
        expect(store.fields.value[0].label).toBe('Name');
        expect(wrapper.find('.canvas__field-main').text()).toContain('Name');
    });

    it('writes nothing for an Escape followed by a blur', async () => {
        // Escape unmounts the input, and a browser may still send it a blur: that blur must not save the discarded text.
        const { store, wrapper, patches } = editable();
        await open(wrapper);
        const input = wrapper.find('input[aria-label="Question label"]');
        await input.setValue('Something else');
        input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        input.element.dispatchEvent(new FocusEvent('blur'));
        await flushPromises();

        expect(patches()).toHaveLength(0);
        expect(store.fields.value[0].label).toBe('Name');
    });

    it('keeps the label when it is left blank, and writes nothing', async () => {
        const { store, wrapper, patches } = editable();
        await open(wrapper);
        await wrapper.find('input[aria-label="Question label"]').setValue('   ');
        await wrapper.find('input[aria-label="Question label"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(patches()).toHaveLength(0);
        expect(store.fields.value[0].label).toBe('Name');
    });

    it('saves on blur too, and an Enter followed by a blur saves once', async () => {
        const { wrapper, patches } = editable();
        await open(wrapper);
        const input = wrapper.find('input[aria-label="Question label"]');
        await input.setValue('Full name');
        input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        input.element.dispatchEvent(new FocusEvent('blur'));
        await flushPromises();

        expect(patches()).toHaveLength(1);
    });

    it('leaves Enter to an input method that is composing', async () => {
        const { wrapper, patches } = editable();
        await open(wrapper);
        const input = wrapper.find('input[aria-label="Question label"]');
        await input.setValue('Full name');
        input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true }));
        await flushPromises();

        expect(patches()).toHaveLength(0);
        expect(wrapper.find('input[aria-label="Question label"]').exists()).toBe(true);
    });

    it('opens on a double-click of the row', async () => {
        const { wrapper } = editable();
        await wrapper.find('.canvas__field-main').trigger('dblclick');

        expect(wrapper.find('input[aria-label="Question label"]').exists()).toBe(true);
    });
});
