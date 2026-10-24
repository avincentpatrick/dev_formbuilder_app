import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SuggestionsEditor from './SuggestionsEditor.vue';
import type { LinkableSource } from './types';

/**
 * M184 (`R-596d6afd`) — where a Short text's suggestions come from: none, a list file of this form, or another form's
 * answers. It takes the question's config and emits a whole new one; choosing a source never touches any other key.
 */

const SOURCES: LinkableSource[] = [
    { id: 'src-1', title: 'Facility Register', questions: [{ key: 'facility_name', label: 'Facility name' }] },
    { id: 'src-2', title: 'Staff Roster', questions: [{ key: 'staff_name', label: 'Staff name' }, { key: 'unit', label: 'Unit' }] },
];
const LISTS = [{ name: 'facilities', file_name: 'facilities.xlsx', row_count: 12, columns: ['name', 'label'] }];

afterEach(() => {
    vi.unstubAllGlobals();
});

async function mountWith(config: Record<string, unknown>, formId: string | null = 'form-1') {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: LISTS }), { status: 200 }))));
    const wrapper = mount(SuggestionsEditor, { props: { config, sources: SOURCES, formId } });
    await flushPromises();

    return wrapper;
}

function emitted(wrapper: Awaited<ReturnType<typeof mountWith>>): Record<string, unknown>[] {
    return (wrapper.emitted('update:config') ?? []).map((args) => args[0] as Record<string, unknown>);
}

describe('SuggestionsEditor', () => {
    it('starts with no suggestions, and offers a list file only with the form to read lists from', async () => {
        const wrapper = await mountWith({ max_length: 80 });
        expect((wrapper.find('input[type="radio"][value="none"]').element as HTMLInputElement).checked).toBe(true);
        expect((await mountWith({}, null)).find('input[type="radio"][value="list"]').exists()).toBe(false);
    });

    it('names a list file, keeping every other key', async () => {
        const wrapper = await mountWith({ max_length: 80 });

        await wrapper.find('input[type="radio"][value="list"]').setValue(true);
        expect(emitted(wrapper).at(-1)).toEqual({ max_length: 80, suggestions_source: { kind: 'list', name: '' } });

        await wrapper.setProps({ config: { max_length: 80, suggestions_source: { kind: 'list', name: '' } } });
        await flushPromises();
        await wrapper.find('select').setValue('facilities');
        expect(emitted(wrapper).at(-1)).toEqual({ max_length: 80, suggestions_source: { kind: 'list', name: 'facilities' } });
    });

    it('picks the only question of a form that shares one, and leaves a choice when it shares more', async () => {
        const wrapper = await mountWith({ suggestions_source: { kind: 'form', form_id: null, field_key: null } });

        await wrapper.find('select').setValue('src-1');
        expect(emitted(wrapper).at(-1)).toEqual({ suggestions_source: { kind: 'form', form_id: 'src-1', field_key: 'facility_name' } });

        await wrapper.find('select').setValue('src-2');
        expect(emitted(wrapper).at(-1)).toEqual({ suggestions_source: { kind: 'form', form_id: 'src-2', field_key: null } });
    });

    it('removes the source and any written suggestions when the author turns them off', async () => {
        const wrapper = await mountWith({ suggestions_source: { kind: 'list', name: 'facilities' }, suggestions: ['A'] });

        await wrapper.find('input[type="radio"][value="none"]').setValue(true);
        expect(emitted(wrapper)).toEqual([{}]);
    });

    it('says when the chosen list is not uploaded', async () => {
        const wrapper = await mountWith({ suggestions_source: { kind: 'list', name: 'clinics' } });

        expect(wrapper.find('[data-suggestions-list-note]').text()).toBe('clinics is not uploaded to this form.');
    });
});
