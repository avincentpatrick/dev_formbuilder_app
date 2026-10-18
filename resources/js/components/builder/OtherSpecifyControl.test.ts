import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';

import OtherSpecifyControl from './OtherSpecifyControl.vue';
import { ENUMS, serverField } from './builder-store-fixtures';
import type { LocalField } from './types';
import type { BuilderStore } from './useBuilderStore';

/**
 * M177 (`R-71509975`): the Options tab's "Other, please specify" switch. Which types offer it is transmitted
 * (`enums.other_specify_types`), never listed here; it reads on while the link names a question that exists; turning it
 * off asks first when responses hold answers to the Please specify question (`D133`), and otherwise goes at once.
 */
function local(overrides: Partial<LocalField>): LocalField {
    return { ...serverField(overrides), uid: overrides.id ?? 'u1', ...overrides } as LocalField;
}

function double(selected: LocalField, all: LocalField[], answers = 0) {
    const store = {
        selectedField: computed(() => selected),
        fields: ref(all),
        enums: { ...ENUMS, other_specify_types: ['single_select', 'multi_select', 'dropdown'] },
        addOtherSpecify: vi.fn().mockResolvedValue(undefined),
        removeOtherSpecify: vi.fn().mockResolvedValue(undefined),
        answersFor: vi.fn().mockResolvedValue(answers),
    };

    return store as typeof store & BuilderStore;
}

function mountControl(store: BuilderStore) {
    return mount(OtherSpecifyControl, { props: { store }, global: { stubs: { teleport: true } }, attachTo: document.body });
}

const switchOf = (wrapper: ReturnType<typeof mountControl>) => wrapper.get<HTMLInputElement>('[data-other-specify] input[type="checkbox"]');

afterEach(() => {
    document.body.innerHTML = '';
});

const choice = local({ id: 'f-job', uid: 'u-job', key: 'occupation', field_type: 'single_select', config: { options: [{ value: 'farmer', label: 'Farmer' }] } });
const paired = local({
    id: 'f-job',
    uid: 'u-job',
    key: 'occupation',
    field_type: 'single_select',
    config: { options: [{ value: 'other', label: 'Other' }], other_specify_key: 'occupation_other' },
});
const child = local({ id: 'f-other', uid: 'u-other', key: 'occupation_other', field_type: 'short_text', label: 'Please specify' });

describe('OtherSpecifyControl (M177)', () => {
    it('is offered for a transmitted type only, and never for choices taken from another form', () => {
        expect(mountControl(double(choice, [choice])).find('[data-other-specify]').exists()).toBe(true);

        const scale = local({ field_type: 'likert_scale', config: { options: [] } });
        expect(mountControl(double(scale, [scale])).find('[data-other-specify]').exists()).toBe(false);

        const linked = local({ field_type: 'single_select', config: { options: [], options_source: { form_id: 'x', field_key: 'y' } } });
        expect(mountControl(double(linked, [linked])).find('[data-other-specify]').exists()).toBe(false);
    });

    it('reads on while the link names a question that exists, and off when it names nothing', () => {
        expect(switchOf(mountControl(double(paired, [paired, child]))).element.checked).toBe(true);
        expect(switchOf(mountControl(double(paired, [paired]))).element.checked).toBe(false);
        expect(switchOf(mountControl(double(choice, [choice]))).element.checked).toBe(false);
    });

    it('turns the pair on through the store', async () => {
        const store = double(choice, [choice]);
        await switchOf(mountControl(store)).setValue(true);

        expect(store.addOtherSpecify).toHaveBeenCalledWith('u-job');
    });

    it('turns it off at once when no response answered Please specify', async () => {
        const store = double(paired, [paired, child], 0);
        await switchOf(mountControl(store)).setValue(false);
        await flushPromises();

        expect(store.answersFor).toHaveBeenCalledWith('u-other');
        expect(store.removeOtherSpecify).toHaveBeenCalledWith('u-job');
    });

    it('asks first, saying how many responses answered it, when some did — and keeps it on a cancel', async () => {
        const store = double(paired, [paired, child], 3);
        const wrapper = mountControl(store);
        await switchOf(wrapper).setValue(false);
        await flushPromises();

        expect(store.removeOtherSpecify).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('3 responses hold answers to this question.');

        const button = (name: string) => [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((b) => b.textContent?.trim() === name)!;
        button('Cancel').click();
        await flushPromises();
        expect(store.removeOtherSpecify).not.toHaveBeenCalled();
        expect(switchOf(wrapper).element.checked).toBe(true);

        await switchOf(wrapper).setValue(false);
        await flushPromises();
        button('Delete question').click();
        await flushPromises();
        expect(store.removeOtherSpecify).toHaveBeenCalledWith('u-job');
    });
});
