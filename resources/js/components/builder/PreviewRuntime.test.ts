import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PreviewRuntime from './PreviewRuntime.vue';
import { buildRenderModel } from '../../../public-runtime/lib/schema-mapping';
import { field, schemaResponse, section } from '../../../public-runtime/__tests__/fixtures';

/**
 * The builder preview's repeat path (Increment M123).
 *
 * ⚠️ WHY THIS FILE EXISTS WHEN `PreviewPane.test.ts` DOES. That suite stubs `RepeatGroup` and mocks the engine, so
 * it cannot see what a repeat instance renders — and the preview reuses the respondent's `RepeatGroup` verbatim.
 * Before M123 a calculated or page-break member of a repeatable section rendered in every preview instance as its
 * label plus "Not available for manual entry yet (Phase 2).", telling the author that a question they had built
 * correctly was broken. This mounts a REAL engine over a snapshot, which is what `PreviewRuntime` does in the app.
 */
describe('PreviewRuntime — a repeatable section with members that render nothing (M123)', () => {
    it('renders each preview instance with its real input and no unsupported notice', async () => {
        const snapshot = schemaResponse({
            sections: [
                section({ key: 'hh', label: 'Household members', is_repeatable: true, min_instances: 0, max_instances: 3 }),
            ],
            fields: [
                field({ key: 'member_name', label: 'Member name', section_key: 'hh', section_sequence: 0 }),
                field({ key: 'brk', label: 'Page break', field_type: 'page_break', section_key: 'hh', section_sequence: 1 }),
                field({
                    key: 'total',
                    label: 'Running total',
                    field_type: 'calculated',
                    section_key: 'hh',
                    section_sequence: 2,
                    config: { calculated_formula: '1 + 1' },
                }),
            ],
        });

        const wrapper = mount(PreviewRuntime, {
            props: { snapshot, model: buildRenderModel(snapshot), issuesByKey: {}, selectedKey: null, initialStepKey: null },
        });
        await flushPromises();

        const add = wrapper.findAll('button').find((b) => b.text().includes('Add Household members'));
        expect(add, 'the repeat group must offer its Add control in the preview').toBeDefined();
        await add!.trigger('click');
        await flushPromises();

        const instance = wrapper.find('[data-repeat-instance]');
        expect(instance.exists()).toBe(true);
        expect(instance.findAll('input')).toHaveLength(1);
        expect(instance.text()).toContain('Member name');
        expect(instance.text()).not.toContain('Running total');
        expect(instance.text()).not.toContain('Page break');
        expect(wrapper.text()).not.toContain('Not available for manual entry');

        wrapper.unmount();
    });
});
