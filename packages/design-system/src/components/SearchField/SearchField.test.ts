import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SearchField from './SearchField.vue';

/**
 * Increment J1e.
 *
 * The two things this component is FOR — never disabling the input, and never firing two visits for one
 * keystroke — are both invisible to every other gate in the repo. axe cannot see a duplicate navigation,
 * and an input that blurs mid-word passes every scan.
 */
describe('MdsSearchField — the input is never disabled', () => {
    it('renders no disabled attribute and accepts no prop that could add one', () => {
        // ⚠️ The whole reason there is no `disabled` prop. Every sibling control in a filter bar binds
        // `:disabled="busy"` while a round-trip is in flight; doing it to a focused text input BLURS it and
        // the rest of the user's word goes nowhere. Passing the attribute anyway falls through to the
        // input, so this case also proves the omission is not accidentally bypassable in the obvious way it
        // would be if someone "helpfully" forwarded it.
        const wrapper = mount(SearchField);

        expect(wrapper.get('input').attributes('disabled')).toBeUndefined();
        expect(Object.keys(SearchField.props ?? {})).not.toContain('disabled');

        wrapper.unmount();
    });

    it('renders type="search", which is what produces the searchbox role', () => {
        const wrapper = mount(SearchField);

        expect(wrapper.get('input').attributes('type')).toBe('search');

        wrapper.unmount();
    });

    it('labels the input through FormField, so the control has a name', () => {
        const wrapper = mount(SearchField, { props: { label: 'Search forms' } });

        const label = wrapper.get('label');
        expect(label.text()).toBe('Search forms');
        // Resolved, not just present: a `for` pointing at nothing leaves the input unnamed.
        expect(label.attributes('for')).toBe(wrapper.get('input').attributes('id'));

        wrapper.unmount();
    });
});

describe('MdsSearchField — committing a query', () => {
    it('emits submit on Enter when the box disagrees with what the server ran', () => {
        const wrapper = mount(SearchField, { props: { modelValue: 'clinic', applied: '' } });

        wrapper.get('input').trigger('keyup.enter');

        expect(wrapper.emitted('submit')).toHaveLength(1);

        wrapper.unmount();
    });

    it('emits submit on change, which is how a blur commits', () => {
        const wrapper = mount(SearchField, { props: { modelValue: 'clinic', applied: '' } });

        wrapper.get('input').trigger('change');

        expect(wrapper.emitted('submit')).toHaveLength(1);

        wrapper.unmount();
    });

    it('fires ONCE when Enter also fires the native change, not twice', async () => {
        // ⚠️ THE DEFECT THE LATCH EXISTS FOR. Browsers fire `change` on Enter as well as on blur, so both
        // handlers run for one keystroke — two full Inertia visits, the second racing the first with
        // `replace: true` on both. Deleting the latch reddens exactly this and nothing else.
        const wrapper = mount(SearchField, { props: { modelValue: 'clinic', applied: '' } });

        await wrapper.get('input').trigger('change');
        await wrapper.get('input').trigger('keyup.enter');

        expect(wrapper.emitted('submit')).toHaveLength(1);

        wrapper.unmount();
    });

    it('does not emit when the box already agrees with what the server ran', () => {
        // A page restored from `?q=clinic`: Enter is a deliberate no-op, not a redundant round-trip.
        const wrapper = mount(SearchField, { props: { modelValue: 'clinic', applied: 'clinic' } });

        wrapper.get('input').trigger('keyup.enter');
        wrapper.get('input').trigger('change');

        expect(wrapper.emitted('submit')).toBeUndefined();

        wrapper.unmount();
    });

    it('submits the SAME text again after a Clear reset the applied query', async () => {
        // ⚠️ WHY THE LATCH WATCHES `applied` RATHER THAN REMEMBERING ITS OWN LAST EMIT. Search "clinic",
        // press Clear (the list goes back to everything, `applied` becomes ''), retype "clinic". A private
        // "last emitted" ref still says "clinic" and swallows the Enter — leaving the box reading `clinic`
        // over an unfiltered list, with no way to re-run it short of a reload.
        const wrapper = mount(SearchField, { props: { modelValue: 'clinic', applied: '' } });

        await wrapper.get('input').trigger('keyup.enter');
        expect(wrapper.emitted('submit')).toHaveLength(1);

        // The server answers; the page then clears.
        await wrapper.setProps({ applied: 'clinic' });
        await wrapper.setProps({ modelValue: '', applied: '' });

        // Retyped, verbatim.
        await wrapper.setProps({ modelValue: 'clinic' });
        await wrapper.get('input').trigger('keyup.enter');

        expect(wrapper.emitted('submit')).toHaveLength(2);

        wrapper.unmount();
    });

    it('adopts the CLAMPED query the server ran, so the box cannot disagree with the list', async () => {
        // Every presenter echoes `SearchTerms::raw()` rather than the raw input. Without the watcher that
        // echo has no reader: the box would keep showing the 300-character paste over results computed from
        // 200 characters, and every subsequent Enter would re-submit because the two never match.
        const long = 'x'.repeat(300);
        const clamped = 'x'.repeat(200);

        const wrapper = mount(SearchField, { props: { modelValue: long, applied: '' } });

        await wrapper.get('input').trigger('keyup.enter');
        await wrapper.setProps({ applied: clamped });

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([clamped]);

        wrapper.unmount();
    });

    it('does NOT overwrite the box if the user kept typing while the request was in flight', async () => {
        // The other half of the same rule, and the reason the adopt is guarded. This input is deliberately
        // never disabled so a user can keep typing through a reload; stomping their word from under them
        // would be a worse defect than the one the adopt fixes.
        const wrapper = mount(SearchField, { props: { modelValue: 'clin', applied: '' } });

        await wrapper.get('input').trigger('keyup.enter');
        await wrapper.setProps({ modelValue: 'clinic intake' }); // typed on while the visit was in flight
        await wrapper.setProps({ applied: 'clin' }); // the server answers the OLD query

        const emissions = wrapper.emitted('update:modelValue') ?? [];
        expect(emissions.map((e) => e[0])).not.toContain('clin');

        wrapper.unmount();
    });

    it('emits update:modelValue on every keystroke without committing any of them', async () => {
        const wrapper = mount(SearchField, { props: { modelValue: '', applied: '' } });

        await wrapper.get('input').setValue('cli');

        expect(wrapper.emitted('update:modelValue')).toEqual([['cli']]);
        // Typing is not searching — the user's decision to run the query is Enter or blur, nothing else.
        expect(wrapper.emitted('submit')).toBeUndefined();

        wrapper.unmount();
    });
});
