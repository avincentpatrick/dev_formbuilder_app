import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { MdsModal } from '@meridian/design-system';

import ConvertFieldDialog from './ConvertFieldDialog.vue';
import type { ConsequenceView, ConvertPhase, TargetGroup } from './field-conversion';

/**
 * The type-change dialog, presentational (Increment M123). Rendered in place (`teleport: false` falls through to the
 * `MdsModal` root), and attached to the document wherever a case is about focus: `mount()` renders detached, and a
 * detached element can never be `document.activeElement`.
 */
const GROUPS: TargetGroup[] = [
    { label: 'Question types', options: [{ value: 'long_text', label: 'Long text' }, { value: 'email', label: 'Email' }] },
    { label: 'Not asked', options: [{ value: 'note', label: 'Note / label' }] },
];

const VIEW: ConsequenceView = {
    summary: 'Here is what changing it to Note / label does.',
    question: ['Its requiredness changes from Required to Optional.'],
    kept: null,
    removed: [{ label: 'Must match a pattern', value: 'abc', shows: 'Bad.', reason: 'A note takes no rules.' }],
    added: [],
    warnings: ['A hidden question holds nothing until you choose where its value comes from.'],
    elsewhere: [{ owner: 'The condition on the section “Adults only”', message: 'This uses the question, which will no longer have an answer.' }],
};

function mountDialog(over: Partial<{ phase: ConvertPhase; selected: string; targetLabel: string; view: ConsequenceView | null; message: string | null; groups: TargetGroup[] }> = {}, attach = false) {
    return mount(ConvertFieldDialog, {
        props: {
            open: true,
            phase: 'choosing',
            questionLabel: 'Your age',
            currentLabel: 'Whole number',
            groups: GROUPS,
            selected: '',
            targetLabel: '',
            view: null,
            message: null,
            ...over,
        },
        attrs: { teleport: false },
        ...(attach ? { attachTo: document.body } : {}),
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ConvertFieldDialog', () => {
    it('says it is checking while the plans load, with a busy primary that is never natively disabled (CD1)', () => {
        const wrapper = mountDialog({ phase: 'loading' });

        expect(wrapper.text()).toContain('Change question type');
        expect(wrapper.text()).toContain('Checking which types it can change to');
        expect(wrapper.find('[data-convert-target]').exists()).toBe(false);
        const primary = wrapper.get('[data-convert-primary]');
        expect(primary.attributes('disabled')).toBeUndefined();
        expect(primary.attributes('aria-disabled')).toBe('true');
        expect(wrapper.html()).not.toContain('role="tab"');
    });

    it('offers the targets in a labelled select with both groups, and no consequences before a choice (CD2)', () => {
        const wrapper = mountDialog();

        const select = wrapper.get('[data-convert-target]');
        const label = wrapper.get(`label[for="${select.attributes('id')}"]`);
        expect(label.text()).toContain('Change to');
        expect(wrapper.findAll('optgroup').map((group) => group.attributes('label'))).toEqual(['Question types', 'Not asked']);
        expect(wrapper.find('option[value=""]').text()).toBe('Choose a type');
        expect(wrapper.find('[data-convert-effects]').exists()).toBe(false);
    });

    it('emits the choice, and renders every part of the chosen plan (CD3)', async () => {
        const wrapper = mountDialog();
        await wrapper.get('[data-convert-target]').setValue('note');
        expect(wrapper.emitted('update:selected')).toEqual([['note']]);

        await wrapper.setProps({ selected: 'note', targetLabel: 'Note / label', view: VIEW });
        const effects = wrapper.get('[data-convert-effects]').text();
        expect(effects).toContain('Here is what changing it to Note / label does.');
        expect(effects).toContain('This question');
        expect(effects).toContain('Removes the rule Must match a pattern');
        expect(effects).toContain('A note takes no rules.');
        expect(effects).toContain('Things to check');
        expect(effects).toContain('Elsewhere in the form');
        expect(effects).toContain('The condition on the section “Adults only”');
    });

    it('refuses to apply nothing, saying why and moving to the choice instead (CD4)', async () => {
        const wrapper = mountDialog({}, true);

        await wrapper.get('[data-convert-primary]').trigger('click');
        await nextTick();

        expect(wrapper.text()).toContain('Choose a type first.');
        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(document.activeElement).toBe(wrapper.get('[data-convert-target]').element);
        wrapper.unmount();
    });

    it('applies the chosen type, naming it on the button (CD5)', async () => {
        const wrapper = mountDialog({ selected: 'long_text', targetLabel: 'Long text', view: VIEW });

        expect(wrapper.get('[data-convert-primary]').text()).toBe('Change to Long text');
        await wrapper.get('[data-convert-primary]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('reports a refused change assertively and keeps the choice (CD6)', () => {
        const wrapper = mountDialog({ phase: 'apply-failed', selected: 'email', targetLabel: 'Email', message: 'This question cannot be changed.' });

        const alert = wrapper.get('[role="alert"]');
        expect(alert.text()).toContain('The type wasn’t changed');
        expect(alert.text()).toContain('This question cannot be changed.');
        expect((wrapper.get('[data-convert-target]').element as HTMLSelectElement).value).toBe('email');
    });

    it('moves focus to the warning when the question changed underneath, so a second Enter applies nothing unread (CD7)', async () => {
        const wrapper = mountDialog({ phase: 'applying', selected: 'email', targetLabel: 'Email' }, true);

        await wrapper.setProps({ phase: 'stale', message: 'It is now Long text. Here is what changing it does now.' });
        await nextTick();
        await nextTick();

        expect(document.activeElement).toBe(wrapper.get('[data-convert-stale]').element);
        expect(wrapper.text()).toContain('This question changed while this was open');
        wrapper.unmount();
    });

    it('cannot be dismissed while the change is being applied, and can be otherwise (CD8)', async () => {
        const applying = mountDialog({ phase: 'applying', selected: 'email', targetLabel: 'Email' });
        await applying.get('[data-convert-cancel]').trigger('click');
        expect(applying.emitted('close')).toBeUndefined();

        const choosing = mountDialog();
        await choosing.get('[data-convert-cancel]').trigger('click');
        expect(choosing.emitted('close')).toHaveLength(1);
    });

    it('offers a retry when the plans could not be read (CD10)', async () => {
        const wrapper = mountDialog({ phase: 'load-failed', message: 'Server Error' });

        expect(wrapper.get('[role="alert"]').text()).toContain('Couldn’t check which types it can change to');
        expect(wrapper.get('[data-convert-primary]').text()).toBe('Try again');
        await wrapper.get('[data-convert-primary]').trigger('click');

        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('hands focus back to its opener by selector when the opener cannot take it (CD11)', () => {
        const wrapper = mountDialog();

        expect(wrapper.findComponent(MdsModal).props('returnFocus')).toEqual(['[data-convert-opener]']);
    });

    it('says plainly when there is nothing to change to, and offers no primary (CD12)', () => {
        const wrapper = mountDialog({ groups: [] });

        expect(wrapper.text()).toContain('This question can’t be changed to another type.');
        expect(wrapper.find('[data-convert-primary]').exists()).toBe(false);
    });
});
