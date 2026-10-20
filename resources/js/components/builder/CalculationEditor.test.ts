/**
 * M180 (`R-f77aa45d`, `D155`, `D156`) — the calculation editor: a Type and two modes, Build it (presets, by clicking) and
 * Formula (typed, with the picker). A preset writes the formula shown beneath it; typing in that formula switches to
 * Formula. Still one tablist on the page (none here), and no floating menu in the scrolling panel.
 */

import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import CalculationEditor from './CalculationEditor.vue';
import type { CalculationQuestion } from './calculation-presets';

const QUESTIONS: CalculationQuestion[] = [
    { key: 'dob', label: 'Date of birth', fieldType: 'date' },
    { key: 'start', label: 'Start', fieldType: 'datetime' },
    { key: 'end', label: 'End', fieldType: 'datetime' },
    { key: 'qty', label: 'Quantity', fieldType: 'integer' },
    { key: 'price', label: 'Price', fieldType: 'decimal' },
    { key: 'name', label: 'Name', fieldType: 'short_text' },
];

function mountEditor(config: Record<string, unknown> = {}): VueWrapper {
    const wrapper: VueWrapper = mount(CalculationEditor, {
        props: {
            config,
            questions: QUESTIONS,
            candidates: [],
            onPatch: (patch: Record<string, unknown>) => wrapper.setProps({ config: { ...wrapper.props('config'), ...patch } }),
        },
        attachTo: document.body,
    });

    return wrapper;
}

const radio = (wrapper: VueWrapper, value: string) => wrapper.findAll('input[type="radio"]').find((r) => (r.element as HTMLInputElement).value === value)!;
const select = (wrapper: VueWrapper, hook: string) => wrapper.find(`[data-calc="${hook}"] select`);
const lastPatch = (wrapper: VueWrapper) => (wrapper.emitted('patch') ?? []).at(-1)?.[0] as Record<string, unknown> | undefined;
const formulaBox = (wrapper: VueWrapper) => wrapper.find('[data-calc="formula"] textarea');

describe('CalculationEditor', () => {
    it('offers a Type and Build it / Formula as switches, never a second tablist', () => {
        const wrapper = mountEditor();

        expect(['number', 'text', 'date', 'duration', 'build', 'formula'].every((value) => radio(wrapper, value) !== undefined)).toBe(true);
        expect(wrapper.findAll('[role="tablist"]')).toHaveLength(0);
    });

    it('opens a formula in a preset\'s shape on Build it, and any other on Formula', () => {
        expect((radio(mountEditor({ calculated_formula: 'age(${dob})', calculated_type: 'duration' }), 'build').element as HTMLInputElement).checked).toBe(true);
        expect((radio(mountEditor({ calculated_formula: '${qty} * 2' }), 'formula').element as HTMLInputElement).checked).toBe(true);
        expect((radio(mountEditor({}), 'build').element as HTMLInputElement).checked).toBe(true);
    });

    it('writes a preset\'s formula when one is chosen, and sets the Type it gives', async () => {
        const wrapper = mountEditor();

        await radio(wrapper, 'duration').trigger('change');
        expect(select(wrapper, 'preset').findAll('option').map((option) => option.text())).toEqual(expect.arrayContaining(['Age from a birth date', 'Duration between two dates or times']));

        await select(wrapper, 'preset').setValue('age');

        expect(lastPatch(wrapper)).toMatchObject({ calculated_formula: 'age(${dob})', calculated_type: 'duration', duration_unit: 'years' });
        expect((formulaBox(wrapper).element as HTMLTextAreaElement).value).toBe('age(${dob})');
    });

    it('lists only dates and times as a duration\'s Start and End, and writes the Units into the formula and the display', async () => {
        const wrapper = mountEditor({ calculated_formula: "date_diff(${start}, ${end}, 'days')", calculated_type: 'duration', duration_unit: 'days' });

        expect(select(wrapper, 'start').findAll('option').map((option) => option.text())).toEqual(['Date of birth · dob', 'Start · start', 'End · end', 'Today']);

        await select(wrapper, 'unit').setValue('hours');

        expect(lastPatch(wrapper)).toMatchObject({ calculated_formula: "date_diff(${start}, ${end}, 'hours')", duration_unit: 'hours' });
    });

    it('switches to Formula when the author types in the formula beneath a preset', async () => {
        const wrapper = mountEditor({ calculated_formula: 'age(${dob})', calculated_type: 'duration', duration_unit: 'years' });

        await formulaBox(wrapper).setValue('age(${dob}) + 1');

        expect((radio(wrapper, 'formula').element as HTMLInputElement).checked).toBe(true);
        expect(lastPatch(wrapper)).toMatchObject({ calculated_formula: 'age(${dob}) + 1' });
        expect(wrapper.find('[data-calc="preset"]').exists()).toBe(false);
    });

    it('offers decimal places for a Number only', async () => {
        const wrapper = mountEditor({ calculated_formula: 'sum(${qty}, ${price})', calculated_type: 'number' });

        const places = wrapper.find('[data-calc="places"] input');
        expect(places.exists()).toBe(true);
        await places.setValue('2');
        expect(lastPatch(wrapper)).toMatchObject({ decimal_places: 2 });

        await radio(wrapper, 'text').trigger('change');
        expect(wrapper.find('[data-calc="places"]').exists()).toBe(false);
    });
});
