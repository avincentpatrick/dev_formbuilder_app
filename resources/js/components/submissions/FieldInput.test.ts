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
import { defineComponent, h } from 'vue';
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

function choiceField(overrides: Partial<EncodeField> = {}): EncodeField {
    return {
        key: 'colour',
        field_type: 'single_select',
        label: 'Favourite colour',
        hint: null,
        placeholder: null,
        required: false,
        options: [
            { value: 'red', label: 'Red' },
            { value: 'blue', label: 'Blue' },
            { value: 'green', label: 'Green' },
        ],
        supported: true,
        ...overrides,
    };
}

describe('FieldInput — a single choice is round buttons (M130, D77)', () => {
    it('renders a single choice as a fieldset of radios, and a Dropdown still as a select', () => {
        const single = mount(FieldInput, { props: { field: choiceField(), modelValue: '' } });
        expect(single.find('fieldset legend').text()).toContain('Favourite colour');
        expect(single.findAll('input[type="radio"]').map((input) => input.attributes('value'))).toEqual(['red', 'blue', 'green']);
        expect(single.find('select').exists()).toBe(false);

        const dropdown = mount(FieldInput, { props: { field: choiceField({ field_type: 'dropdown' }), modelValue: '' } });
        expect(dropdown.find('select').exists()).toBe(true);
        expect(dropdown.find('input[type="radio"]').exists()).toBe(false);
    });

    it('shows the answer checked and emits the option chosen', async () => {
        const wrapper = mount(FieldInput, { props: { field: choiceField(), modelValue: 'blue' } });
        const checked = wrapper.findAll('input[type="radio"]').filter((input) => (input.element as HTMLInputElement).checked);
        expect(checked.map((input) => input.attributes('value'))).toEqual(['blue']);

        await wrapper.find('input[type="radio"][value="green"]').trigger('change');
        expect(wrapper.emitted('update:modelValue')).toEqual([['green']]);
    });

    it('offers to clear an optional answer, emits the empty answer, and moves focus to the first radio', async () => {
        const wrapper = mount(FieldInput, { props: { field: choiceField(), modelValue: 'blue', requiredMarker: 'optional' }, attachTo: document.body });
        const clear = wrapper.findAll('button').find((button) => button.text() === 'Clear selection');
        expect(clear).toBeDefined();

        await clear!.trigger('click');
        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);

        await wrapper.setProps({ modelValue: '' });
        expect(wrapper.findAll('button').some((button) => button.text() === 'Clear selection')).toBe(false);
        expect(document.activeElement).toBe(wrapper.find('input[type="radio"]').element);
        wrapper.unmount();
    });

    it('offers no clear for an unanswered question or a required one', () => {
        const unanswered = mount(FieldInput, { props: { field: choiceField(), modelValue: '' } });
        const required = mount(FieldInput, { props: { field: choiceField({ required: true }), modelValue: 'red' } });

        for (const wrapper of [unanswered, required]) {
            expect(wrapper.findAll('button').some((button) => button.text() === 'Clear selection')).toBe(false);
        }
    });

    it('lays the choices out as the author chose, for round buttons and checkboxes alike, and ignores what it does not know', () => {
        const list = (field: EncodeField, value: AnswerValue) =>
            mount(FieldInput, { props: { field, modelValue: value } }).find('[data-choice-list]').classes();

        expect(list(choiceField({ appearance: 'columns-pack' }), '')).toContain('encode-choices--pack');
        expect(list(choiceField({ appearance: 'columns' }), '')).toContain('encode-choices--columns');
        expect(list(choiceField({ field_type: 'multi_select', appearance: 'columns' }), [])).toContain('encode-choices--columns');

        for (const stacked of [list(choiceField({ appearance: 'likert' }), ''), list(choiceField(), '')]) {
            expect(stacked).not.toContain('encode-choices--pack');
            expect(stacked).not.toContain('encode-choices--columns');
        }
    });

    it('describes the group with its hint and its error, and marks each radio with the error', () => {
        // The blocker the plan review found: a summary jump lands on a radio, and until M130 a group's hint and
        // error had no ids at all, so neither was read out there.
        const wrapper = mount(FieldInput, {
            props: { field: choiceField({ hint: 'Pick one.' }), modelValue: '', error: 'Choose a colour.' },
        });
        const describedby = (wrapper.find('fieldset').attributes('aria-describedby') ?? '').split(' ');
        const hint = wrapper.find('.encode-field__help');
        const error = wrapper.find('.encode-field__error');

        expect(describedby).toEqual([hint.attributes('id'), error.attributes('id')]);
        expect(error.text()).toContain('Choose a colour.');
        for (const radio of wrapper.findAll('input[type="radio"]')) {
            expect(radio.attributes('aria-describedby')).toBe(error.attributes('id'));
            expect(radio.attributes('aria-invalid')).toBe('true');
        }
    });

    it('gives each rendered copy of one question its own radio group, likert scale included', () => {
        // A repeat renders the same member key once per instance (InstanceField, and Encode.vue's own loop). Named by
        // the key, the copies were ONE group: choosing in the second cleared the first on screen (`R-de624bb1`).
        const Pair = defineComponent({
            props: { field: { type: Object as () => EncodeField, required: true } },
            setup: (props) => () =>
                h('div', [h(FieldInput, { field: props.field, modelValue: 'red' }), h(FieldInput, { field: props.field, modelValue: 'blue' })]),
        });

        for (const field of [choiceField(), choiceField({ field_type: 'likert_scale' })]) {
            const wrapper = mount(Pair, { props: { field }, attachTo: document.body });
            const groups = wrapper.findAll('fieldset').map((set) => set.findAll('input[type="radio"]'));
            const names = groups.map((radios) => new Set(radios.map((radio) => radio.attributes('name'))));

            expect(names.map((set) => set.size)).toEqual([1, 1]);
            expect([...names[0]][0]).not.toBe([...names[1]][0]);

            const checked = groups.map((radios) => radios.filter((radio) => (radio.element as HTMLInputElement).checked).map((radio) => radio.attributes('value')));
            expect(checked).toEqual([['red'], ['blue']]);
            wrapper.unmount();
        }
    });
});

describe('FieldInput — a note with content blocks (M130, R-c9f50df2, D69)', () => {
    function noteField(content: unknown[] | null): EncodeField {
        return { key: 'intro', field_type: 'note', label: 'Intro note (for the team)', hint: null, placeholder: null, required: false, options: [], content, supported: false };
    }

    it('renders the blocks and keeps the author-only label off the page', () => {
        const wrapper = mount(FieldInput, {
            props: { field: noteField([{ type: 'heading', level: 1, text: 'Before you begin' }, { type: 'paragraph', spans: [{ text: 'Bring your card.' }] }]), modelValue: null },
        });

        expect(wrapper.find('[data-note-content]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Before you begin');
        expect(wrapper.text()).toContain('Bring your card.');
        expect(wrapper.text()).not.toContain('Intro note (for the team)');
    });

    it('renders the label as before when the note has no content', () => {
        for (const content of [null, []]) {
            const wrapper = mount(FieldInput, { props: { field: noteField(content), modelValue: null } });

            expect(wrapper.find('[data-note-content]').exists()).toBe(false);
            expect(wrapper.find('.encode-note').text()).toBe('Intro note (for the team)');
        }
    });

    it('renders markup-shaped content as text, on the one component every channel shares', () => {
        const wrapper = mount(FieldInput, {
            props: { field: noteField([{ type: 'paragraph', spans: [{ text: '<script>alert(1)</script>', link: 'javascript:alert(1)' }] }]), modelValue: null },
        });

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.text()).toContain('<script>alert(1)</script>');
    });
});
