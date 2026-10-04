import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
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
});
