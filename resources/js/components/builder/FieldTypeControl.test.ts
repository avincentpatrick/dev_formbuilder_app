import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';

import FieldTypeControl from './FieldTypeControl.vue';
import { conversionPlan, ENUMS, fetchMock, jsonResponse, PALETTE, pageProps, requestLog, serverField } from './builder-store-fixtures';
import type { LocalField, LocalSection } from './types';
import { useBuilderStore, type BuilderStore } from './useBuilderStore';

/**
 * The Basics tab's type row and its dialog, driven end to end through the REAL store with `fetch` mocked
 * (Increment M123). The teleport is stubbed so the dialog renders in place; the cases about focus attach to the
 * document, because a detached element is never `document.activeElement`.
 */
async function settle(): Promise<void> {
    for (let i = 0; i < 6; i += 1) {
        await flushPromises();
    }
}

function liveStore(field: Parameters<typeof serverField>[0] = {}): BuilderStore {
    const store = useBuilderStore(pageProps({ fields: [serverField(field)] }));
    store.select({ kind: 'field', uid: store.fields.value[0].uid });

    return store;
}

function mountControl(store: BuilderStore, attach = true) {
    return mount(FieldTypeControl, {
        props: { store },
        global: { stubs: { teleport: true } },
        ...(attach ? { attachTo: document.body } : {}),
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('FieldTypeControl', () => {
    it('renders from selectedField and palette alone, which is all ConfigPanel’s test double carries (FT1)', () => {
        const field: LocalField = { ...serverField(), uid: 'f1' };
        const double = {
            selectedField: computed(() => field),
            palette: PALETTE,
            enums: ENUMS,
            fields: ref<LocalField[]>([field]),
            sections: ref<LocalSection[]>([]),
        } as unknown as BuilderStore;

        const wrapper = mountControl(double, false);

        expect(wrapper.get('[data-field-type-control]').text()).toContain('Question type');
        expect(wrapper.text()).toContain('Short text');
        expect(wrapper.get('[data-convert-opener]').text()).toBe('Change type');
        expect(wrapper.find('[data-convert-target]').exists()).toBe(false);
    });

    it('flushes, reads the plans, applies the choice, closes, announces and returns focus (FT2)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan(), conversionPlan({ to: 'note', requires_confirmation: true })] }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })));
        const store = liveStore();
        const wrapper = mountControl(store);

        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();
        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET']);

        await wrapper.get('[data-convert-target]').setValue('long_text');
        await wrapper.get('[data-convert-primary]').trigger('click');
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'POST']);
        expect(wrapper.find('[data-convert-target]').exists()).toBe(false);
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(wrapper.get('.field-type__status').text()).toBe('Changed to Long text. Use Undo to change it back.');
        expect(wrapper.get('.field-type__value').text()).toBe('Long text');
        expect(document.activeElement).toBe(wrapper.get('[data-convert-opener]').element);
        wrapper.unmount();
    });

    it('re-reads the plans after a 409 and says the question changed, without the conflict dialog (FT3)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan()] }))
            .mockResolvedValueOnce(jsonResponse(409, { message: 'changed', current: serverField({ label: 'Theirs', version: 'v9' }) }))
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan({ fingerprint: 'e'.repeat(64) })] }));
        const store = liveStore();
        const wrapper = mountControl(store);

        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();
        await wrapper.get('[data-convert-target]').setValue('long_text');
        await wrapper.get('[data-convert-primary]').trigger('click');
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'POST', 'GET']);
        expect(wrapper.find('[data-convert-stale]').exists()).toBe(true);
        expect(store.conflict.value).toBeNull();
        expect(store.fields.value[0].field_type).toBe('short_text');
        wrapper.unmount();
    });

    it('says why it cannot read the plans while the last save failed, and sends no read (FT4)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, { message: 'The label is too long.' }));
        const store = liveStore();
        store.fields.value[0].label = 'x'.repeat(300);
        store.touch(store.fields.value[0].uid, 'field');
        await store.whenIdle();
        await settle();
        const wrapper = mountControl(store);

        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();

        expect(wrapper.get('[role="alert"]').text()).toContain('hasn’t saved');
        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
        wrapper.unmount();
    });

    it('changes nothing when cancelled (FT5)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan()] }));
        const store = liveStore();
        const wrapper = mountControl(store);

        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();
        await wrapper.get('[data-convert-target]').setValue('long_text');
        await wrapper.get('[data-convert-cancel]').trigger('click');
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET']);
        expect(wrapper.find('[data-convert-target]').exists()).toBe(false);
        expect(store.fields.value[0].field_type).toBe('short_text');
        wrapper.unmount();
    });

    it('does not open over the conflict dialog when the flush itself conflicts (FT6)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'changed', current: serverField({ label: 'Theirs', version: 'v9' }) }),
        );
        const store = liveStore();
        const wrapper = mountControl(store);
        store.fields.value[0].label = 'Mine';
        store.touch(store.fields.value[0].uid, 'field');

        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();

        expect(store.conflict.value).not.toBeNull();
        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
        // Not merely "no plans were read": the store would refuse that read anyway. What must not happen is a
        // SECOND modal opening over the conflict dialog, so the assertion is that no dialog of ours exists at all.
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.find('[data-convert-target]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('clears its announcement once the type moves again, an undo above all (FT7)', async () => {
        fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan()] }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan({ from: 'long_text', to: 'short_text' })] }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ version: 'v3' })));
        const store = liveStore();
        const wrapper = mountControl(store);
        await wrapper.get('[data-convert-opener]').trigger('click');
        await settle();
        await wrapper.get('[data-convert-target]').setValue('long_text');
        await wrapper.get('[data-convert-primary]').trigger('click');
        await settle();
        expect(wrapper.get('.field-type__status').text()).toContain('Changed to Long text');

        await store.undo();
        await settle();

        expect(store.fields.value[0].field_type).toBe('short_text');
        expect(wrapper.get('.field-type__status').text()).toBe('');
        wrapper.unmount();
    });
});
