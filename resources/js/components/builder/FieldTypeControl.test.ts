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

describe('FieldTypeControl — the format switch and “Allow negative numbers” (M125)', () => {
    const MIN_VALUE = {
        value: 'min_value',
        label: 'Minimum value',
        shapes: ['number', 'duration', 'scale'],
        takes_operator: false,
        takes_related_field: false,
        operator_may_be_empty: false,
        governs_requiredness: false,
    };

    function numberStore(field: Parameters<typeof serverField>[0] = {}): BuilderStore {
        const store = useBuilderStore(
            pageProps({
                fields: [serverField({ field_type: 'integer', ...field })],
                enums: { ...ENUMS, validation_rule_types: [...ENUMS.validation_rule_types, MIN_VALUE] },
            }),
        );
        store.select({ kind: 'field', uid: store.fields.value[0].uid });

        return store;
    }

    function radios(wrapper: ReturnType<typeof mountControl>) {
        return wrapper.findAll<HTMLInputElement>('[data-variant-switch] input[type="radio"]');
    }

    function checkedFormat(wrapper: ReturnType<typeof mountControl>): string | undefined {
        return radios(wrapper).find((radio) => radio.element.checked)?.element.value;
    }

    function rowSummary(store: BuilderStore): [string | null, string | null, number][] {
        return store.fields.value[0].validations.map((row) => [row.rule_type, row.rule_value, row.sequence]);
    }

    it('offers the group’s members by their palette labels, and nothing for a type listed alone (FT8)', () => {
        const wrapper = mountControl(liveStore(), false);

        expect(wrapper.findAll('[data-variant-switch] label').map((label) => label.text())).toEqual(['Short text', 'Long text']);
        expect(wrapper.get('[data-variant-switch]').text()).toContain('Text format');
        expect(checkedFormat(wrapper)).toBe('short_text');
        // The minimum is not offered for text, so neither is the toggle that would write one.
        expect(wrapper.find('[data-negative-toggle]').exists()).toBe(false);

        const email = mountControl(liveStore({ field_type: 'email' }), false);
        expect(email.find('[data-variant-switch]').exists()).toBe(false);
    });

    it('applies a switch that needs no review directly — one read, one write, no dialog, one undo (FT9)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan(), conversionPlan({ to: 'note', requires_confirmation: true })] }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(200, { plans: [conversionPlan({ from: 'long_text', to: 'short_text' })] }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ version: 'v3' })));
        const store = liveStore();
        const wrapper = mountControl(store);

        await radios(wrapper)[1].setValue(true);
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'POST']);
        expect(requestLog(mock)[1].body).toEqual({ to: 'long_text', version: 'v1', fingerprint: 'a'.repeat(64) });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(checkedFormat(wrapper)).toBe('long_text');
        expect(wrapper.get('.field-type__status').text()).toBe('Changed to Long text. Use Undo to change it back.');

        await store.undo();
        await settle();
        expect(store.fields.value[0].field_type).toBe('short_text');
        expect(store.canUndo.value).toBe(false);
        expect(checkedFormat(wrapper)).toBe('short_text');
        wrapper.unmount();
    });

    it('opens the dialog with the format chosen when the plan needs review, and a cancel leaves the radio true (FT10)', async () => {
        const review = conversionPlan({ from: 'decimal', to: 'integer', requires_confirmation: true });
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, { plans: [review] }))
            .mockResolvedValueOnce(jsonResponse(200, { plans: [review] }));
        const store = numberStore({ field_type: 'decimal', default_value: '2.5' });
        const wrapper = mountControl(store);

        await radios(wrapper)[0].setValue(true);
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'GET']);
        expect(wrapper.get<HTMLSelectElement>('[data-convert-target]').element.value).toBe('integer');

        await wrapper.get('[data-convert-cancel]').trigger('click');
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'GET']);
        expect(store.fields.value[0].field_type).toBe('decimal');
        // The radio checked itself on the click; with nothing converted it must not go on claiming "Whole number".
        expect(checkedFormat(wrapper)).toBe('decimal');
        wrapper.unmount();
    });

    it('says why a refused switch changed nothing, and its radio ends on the true format (FT11)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, { message: 'The label is too long.' }));
        const store = liveStore();
        store.fields.value[0].label = 'x'.repeat(300);
        store.touch(store.fields.value[0].uid, 'field');
        await store.whenIdle();
        await settle();
        const wrapper = mountControl(store);

        await radios(wrapper)[1].setValue(true);
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
        expect(wrapper.get('.field-type__status').text()).toContain('hasn’t saved');
        expect(checkedFormat(wrapper)).toBe('short_text');
        wrapper.unmount();
    });

    it('sends nothing for a click back while a switch is in flight, and ends on the switch’s outcome (FT12)', async () => {
        let release!: (response: Response) => void;
        const mock = fetchMock()
            .mockReturnValueOnce(new Promise<Response>((resolve) => (release = resolve)))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })));
        const store = liveStore();
        const wrapper = mountControl(store);

        await radios(wrapper)[1].setValue(true);
        await flushPromises();
        await radios(wrapper)[0].setValue(true);
        await flushPromises();
        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET']);

        release(jsonResponse(200, { plans: [conversionPlan()] }));
        await settle();

        expect(requestLog(mock).map((r) => r.method)).toEqual(['GET', 'POST']);
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(checkedFormat(wrapper)).toBe('long_text');
        wrapper.unmount();
    });

    it('writes and removes only its own minimum, keeping every other rule where it is (FT13)', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, serverField({ field_type: 'integer', version: 'v2' })));
        const store = numberStore({
            is_required: 'conditional',
            validations: [
                { rule_type: 'required_if', operator: 'eq', rule_value: 'yes', expression: null, error_message: null, related_field_key: 'consent', sequence: 0 },
                { rule_type: 'max_value', operator: null, rule_value: '120', expression: null, error_message: null, related_field_key: null, sequence: 1 },
            ],
        });
        const wrapper = mountControl(store);
        const box = wrapper.get<HTMLInputElement>('[data-negative-toggle] input[type="checkbox"]');
        expect(box.element.checked).toBe(true);

        await box.setValue(false);
        expect(rowSummary(store)).toEqual([
            ['required_if', 'yes', 0],
            ['max_value', '120', 1],
            ['min_value', '0', 2],
        ]);
        await store.whenIdle();
        await settle();
        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
        expect((requestLog(mock)[0].body as { validations: unknown[] }).validations).toHaveLength(3);

        await box.setValue(true);
        expect(rowSummary(store)).toEqual([
            ['required_if', 'yes', 0],
            ['max_value', '120', 1],
        ]);
        wrapper.unmount();
    });

    it('reads a minimum the Validation tab set, and leaves it to that tab (FT14)', () => {
        const minimum = (value: string) => ({
            rule_type: 'min_value',
            operator: null,
            rule_value: value,
            expression: null,
            error_message: null,
            related_field_key: null,
            sequence: 0,
        });
        const below = mountControl(numberStore({ validations: [minimum('-10')] }), false);
        const box = below.get<HTMLInputElement>('[data-negative-toggle] input[type="checkbox"]');

        expect(box.element.disabled).toBe(true);
        expect(box.element.checked).toBe(true);
        const hint = below.get('[data-negative-toggle] .field-type__hint');
        expect(hint.text()).toContain('Validation tab');
        expect(box.attributes('aria-describedby')).toBe(hint.attributes('id'));

        const above = mountControl(numberStore({ validations: [minimum('5')] }), false);
        expect(above.get<HTMLInputElement>('[data-negative-toggle] input[type="checkbox"]').element.checked).toBe(false);
    });
});
