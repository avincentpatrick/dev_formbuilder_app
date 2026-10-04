import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { DataSharingProps } from './types';

/**
 * M133 (`R-5da4a30f`) — the Data sharing settings section, Connect project v1's source key: switching on only after
 * the author confirms who can see shared answers, a question list that is null for "all" and never empty, and the
 * forms that use this one.
 */

const mocks = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { patch: mocks.patch } }));

const DataSharingPanel = (await import('./DataSharingPanel.vue')).default;

function sharing(overrides: Partial<DataSharingProps> = {}): DataSharingProps {
    return {
        enabled: false,
        field_keys: null,
        published: true,
        questions: [
            { key: 'facility_name', label: 'Facility name' },
            { key: 'district', label: 'District' },
        ],
        used_by: [],
        used_by_others: 0,
        ...overrides,
    };
}

function mountPanel(props: Partial<DataSharingProps> = {}) {
    return mount(DataSharingPanel, { props: { open: true, formId: 'form-1', sharing: sharing(props) } });
}

/** The switch is the first checkbox; the question list's checkboxes follow it. */
function theSwitch(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('input[type="checkbox"]')[0];
}

function lastPatch(): [string, Record<string, unknown>, Record<string, unknown>] {
    return mocks.patch.mock.calls.at(-1) as [string, Record<string, unknown>, Record<string, unknown>];
}

beforeEach(() => {
    mocks.patch.mockReset();
});

describe('DataSharingPanel', () => {
    it('asks before switching on, and sends nothing until the author confirms', async () => {
        const wrapper = mountPanel();

        await theSwitch(wrapper).setValue(true);

        expect(mocks.patch).not.toHaveBeenCalled();
        expect(wrapper.find('[data-sharing-confirm]').text()).toContain('including people who fill it in through its public link');

        await wrapper.findAll('button').find((b) => b.text() === 'Share answers')!.trigger('click');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, body, options] = lastPatch();
        expect(url).toBe('/forms/form-1/data-sharing');
        expect(body).toEqual({ enabled: true, acknowledged: true, field_keys: null });
        expect(options.preserveState).toBe(true);
        expect(wrapper.find('[data-sharing-confirm]').exists()).toBe(false);
    });

    it('leaves sharing off when the author cancels', async () => {
        const wrapper = mountPanel();

        await theSwitch(wrapper).setValue(true);
        await wrapper.findAll('button').find((b) => b.text() === 'Cancel')!.trigger('click');

        expect(mocks.patch).not.toHaveBeenCalled();
        expect((theSwitch(wrapper).element as HTMLInputElement).checked).toBe(false);
    });

    it('switches off at once, and puts the switch back when the server refuses', async () => {
        const wrapper = mountPanel({ enabled: true });

        await theSwitch(wrapper).setValue(false);

        expect(lastPatch()[1]).toEqual({ enabled: false, acknowledged: false, field_keys: null });

        (lastPatch()[2] as { onError: (e: Record<string, string>) => void }).onError({ enabled: 'Refused.' });
        await nextTick();

        expect((theSwitch(wrapper).element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.find('[role="alert"]').text()).toBe('Refused.');
    });

    it('sends the chosen questions as a list, and refuses to send an empty one', async () => {
        const wrapper = mountPanel({ enabled: true });

        await wrapper.find('input[type="radio"][value="chosen"]').setValue(true);
        const save = () => wrapper.findAll('button').find((b) => b.text() === 'Save questions')!;

        // Nothing ticked yet: "only these" with none of them is the empty list, which means nothing.
        expect(save().attributes('disabled')).toBeDefined();

        const district = wrapper.findAll('input[type="checkbox"]').find((c) => c.element.closest('label')?.textContent?.includes('District'))!;
        await district.setValue(true);
        await save().trigger('click');

        expect(lastPatch()[1]).toEqual({ enabled: true, acknowledged: true, field_keys: ['district'] });
    });

    it('sends null, never a list, for every question', async () => {
        const wrapper = mountPanel({ enabled: true, field_keys: ['district'] });

        await wrapper.find('input[type="radio"][value="all"]').setValue(true);
        await wrapper.findAll('button').find((b) => b.text() === 'Save questions')!.trigger('click');

        expect(lastPatch()[1]).toEqual({ enabled: true, acknowledged: true, field_keys: null });
    });

    it('offers no switch for a form that is not published or has nothing to share, and says why', () => {
        const unpublished = mountPanel({ published: false, questions: [] });
        expect(unpublished.find('[data-sharing-reason]').text()).toBe('Publish this form before sharing its answers.');
        expect(unpublished.find('input[type="checkbox"]').exists()).toBe(false);

        const nothing = mountPanel({ questions: [] });
        expect(nothing.find('[data-sharing-reason]').text()).toContain('no questions whose answers can be shared');
        expect(nothing.find('input[type="checkbox"]').exists()).toBe(false);
    });

    it('names the forms that use these answers, and only counts the ones this reader cannot open', () => {
        const wrapper = mountPanel({ used_by: [{ id: 'f2', title: 'Referral' }], used_by_others: 2 });
        const used = wrapper.find('[data-sharing-used-by]').text();

        expect(used).toContain('Referral');
        expect(used).toContain('2 forms you cannot open');

        expect(mountPanel().find('[data-sharing-used-by]').text()).toContain('No published form uses them yet.');
    });
});
