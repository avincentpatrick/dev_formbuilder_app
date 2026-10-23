import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LinkedChoicesEditor from './LinkedChoicesEditor.vue';
import type { LinkableSource } from './types';

/**
 * M133 (`R-5da4a30f`) — where a single-choice or dropdown question's choices come from. It takes the question's whole
 * config and emits a whole new one: typed choices and a link never live together, a link replaces typed choices only
 * after the author agrees, and removing the link is a null key.
 */

const SOURCES: LinkableSource[] = [
    { id: 'src-1', title: 'Facility Register', questions: [{ key: 'facility_name', label: 'Facility name' }, { key: 'district', label: 'District' }] },
    { id: 'src-2', title: 'Staff Roster', questions: [{ key: 'staff_name', label: 'Staff name' }] },
];

function mountWith(config: Record<string, unknown>, sources: LinkableSource[] = SOURCES) {
    return mount(LinkedChoicesEditor, { props: { config, sources } });
}

function emitted(wrapper: ReturnType<typeof mountWith>): Record<string, unknown>[] {
    return (wrapper.emitted('update:config') ?? []).map((args) => args[0] as Record<string, unknown>);
}

describe('LinkedChoicesEditor', () => {
    it('starts a link at once when nothing is typed, clearing the typed list in the same change', async () => {
        const wrapper = mountWith({ options: [], layout: 'kept' });

        await wrapper.find('input[type="radio"][value="form"]').setValue(true);

        expect(emitted(wrapper)).toEqual([{ options: [], layout: 'kept', options_source: { form_id: null, field_key: null } }]);
    });

    it('asks before removing typed choices, and changes nothing when the author keeps them', async () => {
        const wrapper = mountWith({ options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] });

        await wrapper.find('input[type="radio"][value="form"]').setValue(true);

        expect(emitted(wrapper)).toEqual([]);
        expect(wrapper.find('[data-linked-choices-confirm]').text()).toContain('The 2 choices typed here will be removed.');

        await wrapper.findAll('button').find((b) => b.text() === 'Keep typed choices')!.trigger('click');
        expect(emitted(wrapper)).toEqual([]);
        expect(wrapper.find('[data-linked-choices-confirm]').exists()).toBe(false);
    });

    it('removes the typed choices and links in ONE change once the author agrees', async () => {
        const wrapper = mountWith({ options: [{ value: 'a', label: 'A' }] });

        await wrapper.find('input[type="radio"][value="form"]').setValue(true);
        await wrapper.findAll('button').find((b) => b.text() === 'Remove and continue')!.trigger('click');

        expect(emitted(wrapper)).toEqual([{ options: [], options_source: { form_id: null, field_key: null } }]);
    });

    it('picks the only question of a form that shares one, and leaves a choice when it shares more', async () => {
        const linked = mountWith({ options: [], options_source: { form_id: null, field_key: null } });

        await linked.find('select').setValue('src-2');
        expect(emitted(linked).at(-1)).toEqual({ options: [], options_source: { form_id: 'src-2', field_key: 'staff_name' } });

        await linked.find('select').setValue('src-1');
        expect(emitted(linked).at(-1)).toEqual({ options: [], options_source: { form_id: 'src-1', field_key: null } });
    });

    it('writes the chosen question beside the chosen form', async () => {
        const wrapper = mountWith({ options: [], options_source: { form_id: 'src-1', field_key: null } });

        const selects = wrapper.findAll('select');
        expect(selects).toHaveLength(2);
        await selects[1].setValue('district');

        expect(emitted(wrapper).at(-1)).toEqual({ options: [], options_source: { form_id: 'src-1', field_key: 'district' } });
    });

    it('removes the link as a null key when the author goes back to typing', async () => {
        const wrapper = mountWith({ options: [], options_source: { form_id: 'src-1', field_key: 'district' } });

        await wrapper.find('input[type="radio"][value="here"]').setValue(true);

        expect(emitted(wrapper)).toEqual([{ options: [], options_source: null }]);
    });

    it('says when no form shares its answers, and when the linked one no longer does', () => {
        expect(mountWith({ options_source: { form_id: null, field_key: null } }, []).find('[data-linked-choices-empty]').exists()).toBe(true);

        const gone = mountWith({ options_source: { form_id: 'src-9', field_key: 'x' } });
        expect(gone.find('[data-linked-choices-unavailable]').text()).toContain('no longer shares them with you');
    });

    it('offers no list file without the form to read its lists from', () => {
        expect(mountWith({ options: [] }).find('input[type="radio"][value="list"]').exists()).toBe(false);
    });
});

/**
 * M184 (`R-3e7c81bb`) — the third place: a list file of this form, named on the question and written into its choices at
 * publish. One question, one source: choosing any clears the others' keys in the same change.
 */
describe('LinkedChoicesEditor — a list file', () => {
    const LISTS = [
        { name: 'facilities', file_name: 'facilities.xlsx', row_count: 12, columns: ['name', 'label'] },
        { name: 'municipalities', file_name: 'municipalities.csv', row_count: 1642, columns: ['name', 'label', 'province'] },
        { name: 'barangays', file_name: 'barangays.csv', row_count: 42010, columns: ['name', 'label', 'municipality'] },
    ];

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    async function mountListed(config: Record<string, unknown>, fieldType = 'single_select') {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: LISTS }), { status: 200 }))));
        const wrapper = mount(LinkedChoicesEditor, { props: { config, sources: SOURCES, formId: 'form-1', fieldType } });
        await flushPromises();

        return wrapper;
    }

    it('names the list on the question, clearing a link and the typed choices in one change', async () => {
        const wrapper = await mountListed({ options: [], options_source: { form_id: 'src-1', field_key: 'district' } });

        await wrapper.find('input[type="radio"][value="list"]').setValue(true);
        expect(emitted(wrapper).at(-1)).toEqual({ options: [], options_list: '' });

        await wrapper.setProps({ config: { options: [], options_list: '' } });
        await flushPromises();
        await wrapper.find('select').setValue('facilities');
        expect(emitted(wrapper).at(-1)).toEqual({ options: [], options_list: 'facilities' });
    });

    it('asks before removing typed choices for a list too', async () => {
        const wrapper = await mountListed({ options: [{ value: 'a', label: 'A' }] });

        await wrapper.find('input[type="radio"][value="list"]').setValue(true);
        expect(emitted(wrapper)).toEqual([]);

        await wrapper.findAll('button').find((b) => b.text() === 'Remove and continue')!.trigger('click');
        expect(emitted(wrapper)).toEqual([{ options: [], options_list: '' }]);
    });

    it('drops the list name when the author goes back to typing or links another form', async () => {
        const typed = await mountListed({ options: [], options_list: 'facilities' });
        await typed.find('input[type="radio"][value="here"]').setValue(true);
        expect(emitted(typed)).toEqual([{ options: [], options_source: null }]);

        const linked = await mountListed({ options: [], options_list: 'facilities' });
        await linked.find('input[type="radio"][value="form"]').setValue(true);
        expect(emitted(linked)).toEqual([{ options: [], options_source: { form_id: null, field_key: null } }]);
    });

    it('says what a long list means on paper (D160), what publish will refuse, and when the file is missing', async () => {
        const note = async (list: string, fieldType = 'single_select') =>
            (await mountListed({ options: [], options_list: list }, fieldType)).find('[data-linked-choices-list-note]');

        expect((await note('facilities')).exists()).toBe(false);
        expect((await note('municipalities')).text()).toBe('This list has 1,642 choices. A Dropdown is easier to use, and the paper form prints every choice.');
        expect((await note('municipalities', 'dropdown')).exists()).toBe(false);
        expect((await note('barangays', 'dropdown')).text()).toBe('This list has 42,010 choices, and one question takes at most 5,000. Use a cascading select for a longer list.');
        expect((await note('clinics')).text()).toBe('clinics is not uploaded to this form.');
    });

    it('shows the chosen list in the picker before the lists arrive, and says nothing is missing until they have', async () => {
        // Measured in e2e: a select drawn with a value none of its options carries shows blank, and the value is not applied
        // again when the options land later.
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));
        const wrapper = mount(LinkedChoicesEditor, { props: { config: { options: [], options_list: 'facilities' }, sources: SOURCES, formId: 'form-1', fieldType: 'dropdown' } });
        await flushPromises();

        expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('facilities');
        expect(wrapper.find('[data-linked-choices-list-note]').exists()).toBe(false);
    });

    it('takes a file uploaded here as the question\'s list when none is chosen yet', async () => {
        const wrapper = await mountListed({ options: [], options_list: '' });
        const added = { name: 'clinics', file_name: 'clinics.xlsx', row_count: 3, columns: ['name', 'label'] };
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: added }), { status: 201 }))));

        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'clinics.xlsx')], configurable: true });
        await input.trigger('change');
        await flushPromises();

        expect(emitted(wrapper).at(-1)).toEqual({ options: [], options_list: 'clinics' });
    });
});
