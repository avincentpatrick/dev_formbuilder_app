/**
 * Show only (M186, `R-ed7f3127`): which forms and questions are offered to narrow a cascade level's or a list's choices,
 * and the one value emitted — through the rendered DOM. Only questions whose answers can match are offered: a Philippine
 * places level by the same level of another places question, any other level by a level with its key or a one-answer
 * question, a list by a one-answer question.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ChoiceFilterEditor from './ChoiceFilterEditor.vue';
import type { LinkableSource } from './types';

const SOURCES: LinkableSource[] = [
    {
        id: 'report',
        title: 'Barangay Report',
        questions: [
            {
                key: 'place',
                label: 'Place',
                field_type: 'cascading_select',
                levels: [
                    { key: 'region', list: 'psgc:region' },
                    { key: 'barangay', list: 'psgc:barangay' },
                ],
            },
            { key: 'team', label: 'Team', field_type: 'short_text' },
        ],
    },
    { id: 'register', title: 'Facility Register', questions: [{ key: 'facility', label: 'Facility', field_type: 'short_text' }] },
];

function mountEditor(target: Record<string, unknown>, filter: unknown = null): VueWrapper {
    return mount(ChoiceFilterEditor, { props: { filter, sources: SOURCES, target } as never });
}

const optionTexts = (wrapper: VueWrapper, index: number): string[] =>
    wrapper
        .findAll('select')
        [index].findAll('option')
        .map((o) => o.text())
        .filter((text) => text !== '' && !text.startsWith('Choose'));

describe('Show only', () => {
    it('offers every choice, or only those another form has — or has no — answer for', () => {
        const wrapper = mountEditor({ kind: 'list' });

        expect(optionTexts(wrapper, 0)).toEqual(['Every choice', 'Only those another form has an answer for', 'Only those another form has no answer for']);
        expect(wrapper.findAll('select')).toHaveLength(1);
    });

    it('offers a Philippine places level only the same level of another places question', () => {
        const wrapper = mountEditor({ kind: 'level', key: 'brgy', list: 'psgc:barangay' }, { form_id: 'report', field_key: null, mode: 'present' });

        expect(optionTexts(wrapper, 1)).toEqual(['Barangay Report']);
        expect(optionTexts(wrapper, 2)).toEqual(['Place']);
    });

    it('offers a list only the questions with one answer', () => {
        const wrapper = mountEditor({ kind: 'list' }, { form_id: 'report', field_key: null, mode: 'present' });

        expect(optionTexts(wrapper, 1)).toEqual(['Barangay Report', 'Facility Register']);
        expect(optionTexts(wrapper, 2)).toEqual(['Team']);
    });

    it('emits the mode, the form and — when it has only one that fits — the question, in one value', async () => {
        const wrapper = mountEditor({ kind: 'level', key: 'barangay', list: 'psgc:barangay' });

        await wrapper.findAll('select')[0].setValue('absent');

        expect(wrapper.emitted('update:filter')?.at(-1)).toEqual([{ form_id: 'report', field_key: 'place', mode: 'absent' }]);
    });

    it('emits null to narrow nothing again', async () => {
        const wrapper = mountEditor({ kind: 'list' }, { form_id: 'register', field_key: 'facility', mode: 'present' });

        await wrapper.findAll('select')[0].setValue('');

        expect(wrapper.emitted('update:filter')?.at(-1)).toEqual([null]);
    });

    it('says when no form shares anything that fits, and when the chosen one stopped sharing it', () => {
        const nothing = mount(ChoiceFilterEditor, {
            props: { filter: { form_id: null, field_key: null, mode: 'present' }, sources: [], target: { kind: 'list' } } as never,
        });
        expect(nothing.find('[data-choice-filter-empty]').exists()).toBe(true);

        const gone = mountEditor({ kind: 'list' }, { form_id: 'withdrawn', field_key: 'x', mode: 'present' });
        expect(gone.find('[data-choice-filter-unavailable]').exists()).toBe(true);
    });
});
