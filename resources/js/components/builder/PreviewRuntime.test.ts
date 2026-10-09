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

/**
 * Increment M124 (`R-8c517fb6`) — a stepped preview paginates at a page break exactly as the respondent's form does.
 *
 * ⛔ THE SWITCH TO ONE PAGE IS A PROP CHANGE, NOT A REMOUNT. `PreviewPane` remounts this component only when the
 * engine's `shape` moves, and `shapeOf()` never reads `form` — so the mode must arrive through the LIVE model, the
 * channel `M120` established for the same setting. The snapshot below never changes; only `model` does.
 */
describe('PreviewRuntime — page breaks paginate a stepped preview (M124)', () => {
    function snapshot(singlePage: boolean) {
        return schemaResponse({
            form: { single_page_mode: singlePage },
            sections: [section({ key: 'hh', label: 'Household', description: 'About the people you live with.' })],
            fields: [
                field({ key: 'q1', label: 'First question', section_key: 'hh', sequence: 0, section_sequence: 0 }),
                field({ key: 'pb', field_type: 'page_break', section_key: 'hh', sequence: 1, section_sequence: 1 }),
                field({ key: 'q2', label: 'Second question', section_key: 'hh', sequence: 2, section_sequence: 2 }),
            ],
        });
    }

    function mountStepped() {
        const stepped = snapshot(false);

        return mount(PreviewRuntime, {
            props: { snapshot: stepped, model: buildRenderModel(stepped), issuesByKey: {}, selectedKey: null, initialStepKey: null },
        });
    }

    it('splits the section at the break, and marks the later page as continuing without its description', async () => {
        const wrapper = mountStepped();
        await flushPromises();

        expect(wrapper.text()).toContain('Page 1 of 2');
        expect(wrapper.text()).toContain('First question');
        expect(wrapper.text()).not.toContain('Second question');
        expect(wrapper.text()).toContain('About the people you live with.');

        await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Page 2 of 2');
        expect(wrapper.find('[data-section-heading]').text()).toBe('Household (continued)');
        expect(wrapper.text()).toContain('Second question');
        expect(wrapper.text()).not.toContain('About the people you live with.');

        wrapper.unmount();
    });

    it('follows a switch to one page through the live model, with no remount', async () => {
        const wrapper = mountStepped();
        await flushPromises();
        expect(wrapper.text()).not.toContain('Second question');

        await wrapper.setProps({ model: buildRenderModel(snapshot(true)) });
        await flushPromises();

        expect(wrapper.text()).toContain('First question');
        expect(wrapper.text()).toContain('Second question');
        expect(wrapper.findAll('[data-section-heading]')).toHaveLength(1);

        wrapper.unmount();
    });
});

/**
 * M130 (`R-c9f50df2`) — a note's content in the preview, one heading level deeper than on the respondent's page,
 * because the preview titles its sections with an `h3` (`ContentHeadingBaseKey`, provided by `PreviewRuntime.vue`).
 */
describe('PreviewRuntime — a note with content blocks (M130)', () => {
    it('renders the blocks under the preview’s own section heading, and never the note’s label', async () => {
        const snapshot = schemaResponse({
            sections: [section({ key: 's1', label: 'Welcome' })],
            fields: [
                field({
                    key: 'intro',
                    label: 'Intro note (for the team)',
                    field_type: 'note',
                    section_key: 's1',
                    section_sequence: 0,
                    config: {
                        content: [
                            { type: 'heading', level: 1, text: 'Before you begin' },
                            { type: 'heading', level: 2, text: 'What to bring' },
                            { type: 'paragraph', spans: [{ text: 'Your clinic card.' }] },
                        ],
                    },
                }),
            ],
        });

        const wrapper = mount(PreviewRuntime, {
            props: { snapshot, model: buildRenderModel(snapshot), issuesByKey: {}, selectedKey: null, initialStepKey: null },
        });
        await flushPromises();

        expect(wrapper.find('h3').text()).toBe('Welcome');
        expect(wrapper.find('[data-note-content] h4').text()).toBe('Before you begin');
        expect(wrapper.find('[data-note-content] h5').text()).toBe('What to bring');
        expect(wrapper.text()).toContain('Your clinic card.');
        expect(wrapper.text()).not.toContain('Intro note (for the team)');

        wrapper.unmount();
    });
});

/**
 * M151 (`R-74c3cf35`) — a question's label edited where the preview shows it. The editor sits in the row's tools, above
 * the respondent's control, because the label itself is drawn by the shared `FieldInput`, which the preview may not edit.
 * Through a REAL engine, because which rows are shown is the engine's answer.
 */
describe('PreviewRuntime — the label edit on a question’s row (M151)', () => {
    function snapshot() {
        return schemaResponse({
            sections: [section({ key: 's1', label: 'About you' })],
            fields: [
                field({ key: 'name', label: 'Your name', section_key: 's1', sequence: 0, section_sequence: 0 }),
                field({
                    key: 'intro',
                    label: 'Intro note (for the team)',
                    field_type: 'note',
                    section_key: 's1',
                    sequence: 1,
                    section_sequence: 1,
                    config: { content: [{ type: 'paragraph', spans: [{ text: 'Thank you for coming.' }] }] },
                }),
                field({ key: 'plain', label: 'A plain note', field_type: 'note', section_key: 's1', sequence: 2, section_sequence: 2 }),
                field({
                    key: 'dep',
                    label: 'Shown only for Sam',
                    section_key: 's1',
                    sequence: 3,
                    section_sequence: 3,
                    relevant_expression: "${name} = 'Sam'",
                }),
                field({ key: 'agree', label: 'Do you agree?', field_type: 'yes_no', section_key: 's1', sequence: 4, section_sequence: 4 }),
            ],
        });
    }

    function mountRows(extra: Record<string, unknown> = {}) {
        const snap = snapshot();

        return mount(PreviewRuntime, {
            props: { snapshot: snap, model: buildRenderModel(snap), issuesByKey: {}, selectedKey: null, initialStepKey: null, ...extra },
        });
    }

    function editButton(wrapper: ReturnType<typeof mountRows>, key: string) {
        return wrapper.find(`[data-preview-field="${key}"] [data-preview-edit-label]`);
    }

    it('offers it on a question and on a note that shows its label, and not on a note showing its blocks', async () => {
        const wrapper = mountRows();
        await flushPromises();

        expect(editButton(wrapper, 'name').attributes('aria-label')).toBe('Edit label of Your name');
        expect(editButton(wrapper, 'plain').exists()).toBe(true);
        // The blocks are shown INSTEAD of the label (`D69`), so there is no label on screen to edit beside.
        expect(editButton(wrapper, 'intro').exists()).toBe(false);

        wrapper.unmount();
    });

    it('offers no tools at all on a question its condition hides, whose row is empty', async () => {
        const wrapper = mountRows();
        await flushPromises();

        expect(wrapper.find('[data-preview-field="dep"]').exists()).toBe(true);
        expect(wrapper.find('[data-preview-field="dep"] [data-preview-tools]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('asks the pane to edit, then shows the editor on the stored label and hands back what was typed', async () => {
        const wrapper = mountRows();
        await flushPromises();

        await editButton(wrapper, 'name').trigger('click');
        expect(wrapper.emitted('edit')).toEqual([['name']]);

        await wrapper.setProps({ editingKey: 'name', editingValue: 'Your name' });
        const input = wrapper.find('[data-preview-field="name"] input[aria-label="Question label"]');
        expect((input.element as HTMLInputElement).value).toBe('Your name');
        expect(editButton(wrapper, 'name').exists()).toBe(false);

        await input.setValue('Your full name');
        await input.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('rename')).toEqual([['name', 'Your full name', 'key']]);

        wrapper.unmount();
    });

    it('opens on a double-click of the question’s own name, and never on its input or a choice’s label', async () => {
        const wrapper = mountRows();
        await flushPromises();

        await wrapper.find('[data-preview-field="name"] input').trigger('dblclick');
        await wrapper.find('[data-preview-field="agree"] input').trigger('dblclick');
        const yes = wrapper.findAll('[data-preview-field="agree"] label').find((label) => label.text().includes('Yes'));
        expect(yes, 'a yes/no draws its choices as labels').toBeDefined();
        await yes!.trigger('dblclick');
        await wrapper.find('[data-preview-field="plain"]').trigger('dblclick');
        expect(wrapper.emitted('edit')).toBeUndefined();

        await wrapper.find('[data-preview-field="name"] label').trigger('dblclick');
        await wrapper.find('[data-preview-field="agree"] legend').trigger('dblclick');
        expect(wrapper.emitted('edit')).toEqual([['name'], ['agree']]);

        wrapper.unmount();
    });
});

/**
 * M151 — the "Just added" list is for questions the engine has not met yet. A question in a section its condition hides
 * is in no shown step either, and until M151 it was listed there too, under "These appear on a page as soon as the preview
 * catches up with your edit" — which they never do, since a respondent would not see them.
 */
describe('PreviewRuntime — a hidden section’s questions are not "just added" (M151)', () => {
    it('lists only a question the engine has not met, never one its section’s condition hides', async () => {
        const snap = schemaResponse({
            sections: [
                section({ key: 's1', label: 'About you' }),
                section({ key: 's2', label: 'Only for Sam', relevant_expression: "${name} = 'Sam'" }),
            ],
            fields: [
                field({ key: 'name', label: 'Your name', section_key: 's1', sequence: 0, section_sequence: 0 }),
                field({ key: 'hidden_q', label: 'Asked of Sam', section_key: 's2', sequence: 1, section_sequence: 0 }),
            ],
        });
        // The live model also holds a question added since this engine was built.
        const live = schemaResponse({
            sections: snap.version.schema.sections,
            fields: [...snap.version.schema.fields, field({ key: 'fresh', label: 'Brand new', section_key: 's1', sequence: 2, section_sequence: 1 })],
        });

        const wrapper = mount(PreviewRuntime, {
            props: { snapshot: snap, model: buildRenderModel(live), issuesByKey: {}, selectedKey: null, initialStepKey: null },
        });
        await flushPromises();

        const pending = wrapper.findAll('[data-preview-pending-field]').map((li) => li.attributes('data-preview-pending-field'));
        expect(pending).toEqual(['fresh']);

        wrapper.unmount();
    });
});
