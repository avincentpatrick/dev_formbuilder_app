/**
 * Increment M124 (R-9f296f7e) — the Yes/No control shows an answer that arrives as a boolean.
 *
 * The control emits the strings `'yes'`/`'no'`, and until M124 it could show only those. But three paths seed it
 * with the boolean the server stores: a resumed draft, the staff edit page, and — once Stage 3 reads yes/no answers
 * as booleans — an offline response reopened for conflict review, whose outbox row now carries `true`/`false`. Each
 * showed a saved Yes as nothing selected. The control still EMITS the strings, so nothing downstream changes.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldInput, { type AnswerValue, type EncodeField } from './FieldInput.vue';

function yesNoField(): EncodeField {
    return {
        key: 'consent',
        field_type: 'yes_no',
        label: 'Consent',
        hint: null,
        placeholder: null,
        required: false,
        options: [],
        supported: true,
    };
}

function checkedValues(modelValue: AnswerValue): string[] {
    const wrapper = mount(FieldInput, { props: { field: yesNoField(), modelValue } });

    return wrapper
        .findAll('input[type="radio"]')
        .map((input) => input.element as HTMLInputElement)
        .filter((input) => input.checked)
        .map((input) => input.value);
}

describe('FieldInput — the Yes/No control (M124)', () => {
    it.each<[string, AnswerValue, string[]]>([
        ['a stored true', true, ['yes']],
        ['a stored false', false, ['no']],
        ["the control's own yes", 'yes', ['yes']],
        ["the control's own no", 'no', ['no']],
        ['unanswered', null, []],
    ])('shows %s', (_name, modelValue, expected) => {
        expect(checkedValues(modelValue)).toEqual(expected);
    });

    it('still emits the control vocabulary when a stored boolean is changed', async () => {
        const wrapper = mount(FieldInput, { props: { field: yesNoField(), modelValue: true } });

        await wrapper.find('input[type="radio"][value="no"]').trigger('change');

        expect(wrapper.emitted('update:modelValue')).toEqual([['no']]);
    });
});
