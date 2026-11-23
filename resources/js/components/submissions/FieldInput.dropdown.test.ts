/**
 * M249 (`dropdown-placeholder`, `dropdown-cannot-clear`, `D233`) — the dropdown question.
 *
 * Its empty choice reads the question's own placeholder ("Choose…"), falling back to "Select an option"; an optional
 * dropdown can be cleared back to it, a required one cannot; and a choice's short label reaches the closed box.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldInput, { type EncodeField } from './FieldInput.vue';

function dropdown(overrides: Partial<EncodeField> = {}): EncodeField {
    return {
        key: 'use',
        field_type: 'dropdown',
        label: 'Should this indicator be used?',
        hint: null,
        placeholder: null,
        required: false,
        options: [
            { value: 'include', label: 'Include. Use it as it stands.', shortLabel: 'Include' },
            { value: 'drop', label: 'Drop it' },
        ],
        supported: true,
        ...overrides,
    };
}

describe('FieldInput — the dropdown placeholder (M249)', () => {
    it('reads the question\'s own placeholder, and "Select an option" when it has none', () => {
        const own = mount(FieldInput, { props: { field: dropdown({ placeholder: 'Choose…' }), modelValue: null } });
        expect(own.get('option[value=""]').text()).toBe('Choose…');
        own.unmount();

        const none = mount(FieldInput, { props: { field: dropdown(), modelValue: null } });
        expect(none.get('option[value=""]').text()).toBe('Select an option');
        none.unmount();
    });

    it('lets an optional dropdown be cleared back to its placeholder, and a required one not', () => {
        const optional = mount(FieldInput, { props: { field: dropdown(), modelValue: 'drop' } });
        expect(optional.get('option[value=""]').attributes('disabled')).toBeUndefined();
        optional.unmount();

        const required = mount(FieldInput, { props: { field: dropdown({ required: true }), modelValue: 'drop' } });
        expect(required.get('option[value=""]').attributes('disabled')).toBeDefined();
        required.unmount();

        // A question required only when another answer says so follows the live marker the page passes.
        const conditional = mount(FieldInput, { props: { field: dropdown(), modelValue: 'drop', requiredMarker: 'required' } });
        expect(conditional.get('option[value=""]').attributes('disabled')).toBeDefined();
        conditional.unmount();
    });

    it('shows a chosen choice\'s short label in the closed box and keeps the full text in the list', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown(), modelValue: 'include' } });

        expect(wrapper.get('[data-select-short]').text()).toBe('Include');
        expect(wrapper.get('option[value="include"]').text()).toBe('Include. Use it as it stands.');
        wrapper.unmount();
    });
});
