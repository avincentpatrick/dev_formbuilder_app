/**
 * The "Insert a question" picker's box (M178, `R-75719ac4`). happy-dom has no `execCommand`, so a pick here writes
 * through `insertAtCaret()`'s fallback; the native undo is `tests/e2e/builder-insert-question.spec.ts`'s to prove.
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import ReferenceInput from './ReferenceInput.vue';
import type { ReferenceCandidate } from './reference-candidates';

const CANDIDATES: ReferenceCandidate[] = [
    { key: 'name', label: 'Your name', group: 'Not in a section', insert: '${name}' },
    { key: 'nation', label: 'Nationality', group: 'Not in a section', insert: '${nation}' },
    { key: 'size', label: 'Household size', group: 'Household', insert: '${size}' },
];

/** Mounted as a `v-model` would hold it: Vue re-syncs a box's value from its prop on every render. */
function mountBox(candidates: ReferenceCandidate[] = CANDIDATES, modelValue = '') {
    const wrapper = mount(ReferenceInput, {
        props: {
            modelValue,
            candidates,
            'aria-label': 'Label',
            'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }),
        },
        attachTo: document.body,
    });

    return wrapper;
}

/** Type into the box as a browser would: the value and the caret move, then `input` fires. */
async function typeInto(wrapper: ReturnType<typeof mountBox>, value: string) {
    const area = wrapper.get('textarea');
    (area.element as HTMLTextAreaElement).value = value;
    (area.element as HTMLTextAreaElement).setSelectionRange(value.length, value.length);
    await area.trigger('input');

    return area;
}

const lastValue = (wrapper: ReturnType<typeof mountBox>) => {
    const events = wrapper.emitted('update:modelValue') ?? [];

    return events[events.length - 1]?.[0];
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ReferenceInput — typing @ to pick a question', () => {
    it('opens a list of the questions the typed letters match, grouped by section, each with its label and code', async () => {
        const wrapper = mountBox();
        const area = await typeInto(wrapper, 'How old is @na');

        const list = wrapper.get('[role="listbox"]');
        expect(list.findAll('[role="option"]').map((option) => option.text())).toEqual(['Your name · name', 'Nationality · nation']);
        expect(list.findAll('[role="group"]').map((group) => group.attributes('aria-label'))).toEqual(['Not in a section']);
        // The box stays the focus; the list is tied to it only while it exists.
        expect(area.attributes('aria-controls')).toBe(list.attributes('id'));
        expect(area.attributes('aria-activedescendant')).toBe(list.findAll('[role="option"]')[0].attributes('id'));
    });

    it('writes the highlighted question in place of what was typed, on Enter', async () => {
        const wrapper = mountBox();
        const area = await typeInto(wrapper, 'How old is @na');

        await area.trigger('keydown', { key: 'ArrowDown' });
        await area.trigger('keydown', { key: 'Enter' });

        expect(lastValue(wrapper)).toBe('How old is ${nation}');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(area.attributes('aria-controls')).toBeUndefined();
    });

    it('opens on ${ too, and a pointer pick writes the question without leaving the box', async () => {
        const wrapper = mountBox();
        await typeInto(wrapper, '. <= ${si');

        await wrapper.get('[role="option"]').trigger('mousedown');

        expect(lastValue(wrapper)).toBe('. <= ${size}');
    });

    it('closes on Escape and leaves the text as typed, without letting a surrounding dialog see the key', async () => {
        const wrapper = mountBox();
        const area = await typeInto(wrapper, 'Ask @na');
        let reachedDialog = false;
        document.body.addEventListener('keydown', () => (reachedDialog = true));

        await area.trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(lastValue(wrapper)).toBe('Ask @na');
        expect(reachedDialog).toBe(false);
    });

    it('says when nothing matches, and leaves an email address alone', async () => {
        const wrapper = mountBox();
        await typeInto(wrapper, 'Ask @zz');
        expect(wrapper.get('[role="listbox"]').text()).toContain('No question matches “zz”');

        await typeInto(wrapper, 'write to ana@ex');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});

describe('ReferenceInput — the Insert a question button', () => {
    it('searches the questions and writes the chosen one at the caret', async () => {
        const wrapper = mountBox(CANDIDATES, 'How old is ?');
        const area = wrapper.get('textarea').element as HTMLTextAreaElement;
        area.value = 'How old is ?';
        area.setSelectionRange(11, 11);

        await wrapper.findAll('button').find((button) => button.text() === 'Insert a question')!.trigger('click');
        const options = wrapper.findAll('[role="option"]');
        expect(options.map((option) => option.text())).toEqual(['Your name · name', 'Nationality · nation', 'Household size · size']);

        await options[2].trigger('click');

        expect(lastValue(wrapper)).toBe('How old is ${size}?');
    });

    it('offers no button and no list where the spot has nothing to offer', async () => {
        const wrapper = mountBox([]);
        await typeInto(wrapper, 'Ask @na');

        expect(wrapper.findAll('button').some((button) => button.text() === 'Insert a question')).toBe(false);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(wrapper.get('textarea').attributes('aria-autocomplete')).toBeUndefined();
    });
});
