/**
 * M243 (`R-1da063f4`) — the Words / Choices pair at the top of the Validation tab. A cleared box, or anything below 1, is no
 * limit (null), so the engines' whole-number rule never meets a zero or a fraction typed here.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { MdsNumberInput } from '@meridian/design-system';
import AnswerLimitsEditor from './AnswerLimitsEditor.vue';

describe('AnswerLimitsEditor (M243)', () => {
    it.each([
        ['words', 'Words'],
        ['choices', 'Choices'],
    ] as const)('names the %s pair', (kind, heading) => {
        const wrapper = mount(AnswerLimitsEditor, { props: { kind, min: null, max: 2 } });

        expect(wrapper.get('h3').text()).toBe(heading);
        expect((wrapper.get('[data-answer-limit="max"]').element as HTMLInputElement).value).toBe('2');
    });

    it('sends a whole number, and no limit for a cleared box, a zero or a fraction below 1', () => {
        const wrapper = mount(AnswerLimitsEditor, { props: { kind: 'words', min: null, max: null } });
        const [min] = wrapper.findAllComponents(MdsNumberInput);

        for (const value of [1500, null, 0, 0.5, 2.7]) {
            min.vm.$emit('update:modelValue', value);
        }

        expect(wrapper.emitted('update:min')).toEqual([[1500], [null], [null], [null], [2]]);
    });
});
