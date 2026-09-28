import { flushPromises, mount } from '@vue/test-utils';
import { computed, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * Increment M118, `B7` / `R-14ce6e05` — the Preview view through the rendered DOM.
 *
 * `preview-model.test.ts` pins the projection and the two channels as pure functions. This pins the thing a
 * pure function cannot fail at and on which the whole feature's affordability rests: WHETHER THE ENGINE IS
 * ACTUALLY REBUILT, and exactly when.
 *
 * ⛔ THAT IS WHY `createFormRuntime` IS MOCKED RATHER THAN REAL. The property under test is a CALL COUNT, and
 * the real factory cannot report one. `draft-snapshot.test.ts` already asserts `shape` ignores a label at the
 * digest level; this asserts the consequence the digest exists for — one keystroke in a label must not tear
 * the engine down. Asserting the digest and assuming the wiring is how a gate ends up decorative.
 *
 * ⛔ EVERY CASE FLUSHES BEFORE IT ADVANCES THE CLOCK, AND THAT ORDER IS LOAD-BEARING RATHER THAN TIDY.
 * A Vue watcher runs as a queued pre-flush job on the next microtask, so the `setTimeout` it schedules does
 * not exist yet at the moment the state changes. `vi.advanceTimersByTime()` called before `await
 * flushPromises()` therefore advances a clock with nothing on it, the rebuild never fires, and the assertion
 * passes having measured nothing. Measured here, not theorised: three mutants SURVIVED on the wrong order and
 * were CAUGHT the moment it was fixed -- including one that deleted the active gate outright.
 *
 * The child components that need a complete `FormRuntime` (`FieldRow`, `RepeatGroup`) are stubbed for the same
 * reason the store is a double: mounting them would make every case here a test of the guest renderer, which
 * `public-runtime`'s own suites already cover, and would need the mock to grow into a second engine.
 */

const created: unknown[] = [];

vi.mock('../../../public-runtime/composables/useFormRuntime', () => ({
    LEAD_STEP_KEY: '__lead__',
    createFormRuntime: (schema: unknown) => {
        created.push(schema);

        return {
            visibleSteps: computed(() => [
                { key: 's1', sectionKey: 's1', title: 'Section one', fieldKeys: ['q1'], isRepeat: false },
                { key: 's2', sectionKey: 's2', title: 'Section two', fieldKeys: ['q2'], isRepeat: false },
            ]),
            currentStep: computed(() => currentStepKey.value === 's1'
                ? { key: 's1', sectionKey: 's1', title: 'Section one', fieldKeys: ['q1'], isRepeat: false }
                : { key: 's2', sectionKey: 's2', title: 'Section two', fieldKeys: ['q2'], isRepeat: false }),
            fieldRelevance: computed(() => ({ q1: true, q2: true, cap: true })),
            goToStep: (key: string) => (currentStepKey.value = key),
            sectionTitleFor: (s: { label: string }) => s.label,
            sectionDescriptionFor: () => null,
            labelFor: (f: { label: string }) => f.label,
            hintFor: (f: { hint: string | null }) => f.hint,
            requiredMarkerFor: () => 'optional',
        };
    },
}));

const currentStepKey = ref('s1');

import PreviewPane from './PreviewPane.vue';
import { PREVIEW_REBUILD_DEBOUNCE_MS } from './preview-model';
import type { BuilderStore } from './useBuilderStore';
import type { LocalField, LocalSection, Selection } from './types';

let uid = 0;

function field(key: string, overrides: Partial<LocalField> = {}): LocalField {
    return {
        id: `fld-${key}`,
        uid: `f${++uid}`,
        form_section_id: null,
        key,
        field_type: 'short_text',
        label: key.charAt(0).toUpperCase() + key.slice(1),
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: {},
        default_value: null,
        is_pii: false,
        is_sensitive: false,
        is_queryable: false,
        indexed_data_type: null,
        sequence: 0,
        section_sequence: null,
        version: null,
        validations: [],
        ...overrides,
    };
}

function section(key: string, overrides: Partial<LocalSection> = {}): LocalSection {
    return {
        id: `sec-${key}`,
        uid: `s${++uid}`,
        key,
        label: key.charAt(0).toUpperCase() + key.slice(1),
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 0,
        version: null,
        ...overrides,
    };
}

interface Double {
    store: BuilderStore;
    fields: ReturnType<typeof ref<LocalField[]>>;
    selected: () => Selection;
}

function makeStore(fields: LocalField[], sections: LocalSection[]): Double {
    const selection = ref<Selection>(null);
    const fieldsRef = ref(fields);

    const store = {
        fields: fieldsRef,
        sections: ref(sections),
        selection,
        selectedField: computed(() => {
            const s = selection.value;

            return s?.kind === 'field' ? (fieldsRef.value.find((f) => f.uid === s.uid) ?? null) : null;
        }),
        select: (next: Selection) => (selection.value = next),
    } as unknown as BuilderStore;

    return { store, fields: fieldsRef, selected: () => selection.value };
}

const FORM = {
    id: 'form-1',
    title: 'Survey',
    description: null,
    default_locale: 'en',
    supported_locales: ['en'],
} as never;

const STUBS = { FieldRow: true, RepeatGroup: true };

function mountPane(double: Double, active = true) {
    return mount(PreviewPane, {
        props: { store: double.store, form: FORM, draft: { id: 'ver-1', version_number: 1 }, active },
        global: { stubs: STUBS },
    });
}

function twoSections(): Double {
    return makeStore(
        [
            field('q1', { form_section_id: 'sec-s1' }),
            field('q2', { form_section_id: 'sec-s2', sequence: 1 }),
        ],
        [section('s1'), section('s2', { sequence: 1 })],
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    created.length = 0;
    currentStepKey.value = 's1';
    uid = 0;
});

afterEach(() => {
    vi.useRealTimers();
});

describe('the engine is built once, and only when Preview is the selected view', () => {
    it('builds immediately on first activation rather than after the debounce', async () => {
        mountPane(twoSections(), true);
        await flushPromises();

        expect(created).toHaveLength(1);
    });

    // ⛔ THE IDLE PROPERTY. Both sibling centre views stay mounted so they keep state across a toggle, so a
    // preview that rebuilds while the author is in Structure makes them pay for an engine nobody is looking at.
    it('builds NOTHING while inactive, however much the structure changes', async () => {
        const double = twoSections();
        mountPane(double, false);
        await flushPromises();

        expect(created).toHaveLength(0);

        double.fields.value = [...double.fields.value, field('q3', { sequence: 2 })];
        await flushPromises();
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS * 4);
        await flushPromises();

        expect(created).toHaveLength(0);
    });

    // ⛔ THE ASSERTION THAT MAKES THE ACTIVATION WATCHER'S SHAPE COMPARISON PROVABLE. Without it, toggling
    // Structure -> Preview -> Structure -> Preview rebuilds the engine every time an author glances at the
    // pane, and no other case in this file would notice.
    it('does not rebuild when toggled away and back with nothing changed', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();
        expect(created).toHaveLength(1);

        await wrapper.setProps({ active: false });
        await flushPromises();
        await wrapper.setProps({ active: true });
        await flushPromises();

        expect(created).toHaveLength(1);
    });

    it('collapses every change made while away into ONE rebuild on re-entry', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, false);
        await flushPromises();

        double.fields.value = [...double.fields.value, field('q3', { sequence: 2 })];
        double.fields.value = [...double.fields.value, field('q4', { sequence: 3 })];
        await flushPromises();

        await wrapper.setProps({ active: true });
        await flushPromises();

        expect(created).toHaveLength(1);
    });
});

describe('a label edit does not rebuild the engine, and a structural edit does', () => {
    // ⛔ THE PROPERTY THE WHOLE TWO-CHANNEL DESIGN EXISTS FOR, ASSERTED AT THE CALL SITE RATHER THAN AT THE
    // DIGEST. If this fails the preview tears down and remounts the engine on every keystroke in a label.
    it('does NOT call createFormRuntime again when only a label changes', async () => {
        const double = twoSections();
        mountPane(double, true);
        await flushPromises();
        expect(created).toHaveLength(1);

        double.fields.value = double.fields.value.map((f) =>
            f.key === 'q1' ? { ...f, label: 'Rewritten while typing' } : f,
        );
        await flushPromises();
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS * 4);
        await flushPromises();

        expect(created).toHaveLength(1);
    });

    it('DOES call it again when a key changes, after the debounce and not before', async () => {
        const double = twoSections();
        mountPane(double, true);
        await flushPromises();

        double.fields.value = double.fields.value.map((f) => (f.key === 'q1' ? { ...f, key: 'renamed' } : f));
        await flushPromises();

        // The window itself is asserted: an immediate rebuild would mean a remount per keystroke in a KEY.
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS - 1);
        await flushPromises();
        expect(created).toHaveLength(1);

        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(created).toHaveLength(2);
    });

    it('coalesces a burst of structural edits into one rebuild', async () => {
        const double = twoSections();
        mountPane(double, true);
        await flushPromises();

        for (const key of ['a', 'b', 'c']) {
            double.fields.value = double.fields.value.map((f) => (f.sequence === 0 ? { ...f, key } : f));
            await flushPromises();
            vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS - 50);
            await flushPromises();
        }

        expect(created).toHaveLength(1);

        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS);
        await flushPromises();

        expect(created).toHaveLength(2);
    });
});

describe('what the author reads', () => {
    it('renders the live label text, not the text the engine was built with', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        double.fields.value = double.fields.value.map((f) =>
            f.key === 'q1' ? { ...f, label: 'Do you consent?' } : f,
        );
        await flushPromises();

        // ASSERTED ON THE PROP, NOT ON RENDERED TEXT, and that is the seam rather than a way around the stub.
        // `FieldRow` renders the field it is PASSED (`props.field`), and `runtime.labelFor(field)` reads that
        // same object — so "the live field reaches the row" IS the property. Asserting rendered text here would
        // really be asserting the guest renderer, which `public-runtime`'s own suites already cover, and it
        // would go green for the wrong reason the moment the stub was replaced by a real mount.
        expect(wrapper.find('[data-preview-field="q1"]').exists()).toBe(true);
        const row = wrapper.findComponent({ name: 'FieldRow' });
        expect((row.props('field') as { label: string }).label).toBe('Do you consent?');
        expect(created).toHaveLength(1);
    });

    // ⛔ THE CAPTURE FIELD IS KEYED `q1` SO THE ENGINE PLACES IT IN A STEP, WHICH IS THE ONLY WAY THE INERT
    // BRANCH IS REACHED AT ALL. A first version used the key `cap`, which the mock's step list does not
    // mention, so the field landed in the pending block and `isCaptureField` was never called -- a mutation
    // making it return `false` unconditionally SURVIVED. Asserting `not.toContain('type="file"')` was no help
    // either: `FieldRow` is stubbed, so no real control is rendered in this suite whatever the branch decides.
    // The assertion has to be that the inert branch RAN and `FieldRow` did NOT.
    it.each(['image_capture', 'file_upload', 'geopoint'])('renders a `%s` field inert, not as a control', async (type) => {
        const double = makeStore([field('q1', { field_type: type })], []);
        const wrapper = mountPane(double, true);
        await flushPromises();

        expect(wrapper.find('[data-preview-inert="q1"]').exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'FieldRow' }).exists()).toBe(false);
        expect(wrapper.text()).toContain('not interactive while you edit');
    });

    it('renders an ordinary field as a real control, not inert', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        expect(wrapper.find('[data-preview-inert="q1"]').exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'FieldRow' }).exists()).toBe(true);
    });

    it('shows a field the engine has not placed yet rather than swallowing it', async () => {
        const double = makeStore([field('brand_new')], []);
        const wrapper = mountPane(double, true);
        await flushPromises();

        expect(wrapper.find('[data-preview-pending-field="brand_new"]').exists()).toBe(true);
    });

    it('names every limitation it has, in the pane itself', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        const text = wrapper.text();
        expect(text).toContain('not interactive');
        expect(text).toContain('default language');
    });

    it('marks the row the config panel has selected', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        const q1 = double.fields.value.find((f) => f.key === 'q1');
        double.store.select({ kind: 'field', uid: q1!.uid });
        await flushPromises();

        expect(wrapper.find('[data-preview-field="q1"]').classes()).toContain('preview__row--selected');
    });
});

describe('the preview drives the config panel, both ways in', () => {
    it('selects the field when its row is clicked', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        await wrapper.find('[data-preview-field="q1"]').trigger('click');

        const q1 = double.fields.value.find((f) => f.key === 'q1');
        expect(double.selected()).toEqual({ kind: 'field', uid: q1!.uid });
    });

    // Keyboard users never click. `focusin` bubbles from the control inside the row, so tabbing into a
    // question selects it too — without adding an interactive wrapper that would owe its own role and name.
    it('selects the field when focus enters its row', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        await wrapper.find('[data-preview-field="q1"]').trigger('focusin');

        expect(double.selected()).not.toBeNull();
    });
});

describe('step movement', () => {
    it('moves between pages and says where the author is', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        expect(wrapper.text()).toContain('Page 1 of 2');

        await wrapper.findAll('button').find((b) => b.text() === 'Next')!.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Page 2 of 2');
    });

    // ⛔ `attemptNext()` REFUSES to advance past a step holding errors, which is right for a respondent and
    // would trap an author inside their own half-built form. The mock deliberately offers no `attemptNext`,
    // so reaching for it would throw rather than quietly work in tests and trap authors in production.
    it('uses goToStep, never attemptNext', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        await expect(
            wrapper.findAll('button').find((b) => b.text() === 'Next')!.trigger('click'),
        ).resolves.not.toThrow();
    });
});

describe('the ARIA invariant this page holds permanently', () => {
    // ⛔ THIRTEEN Playwright locators walk `[role="tab"]` on the builder, four of them loops that CLICK every
    // match, so a second tablist would have its tabs clicked mid-scan. `ConfigPanel.test.ts` asserts the
    // config panel holds exactly one; this asserts the preview adds none — the two together are what the page
    // guarantee is made of, and the e2e case asserts the page-level count for real.
    it('introduces no tab, tablist or tabpanel role', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        const html = wrapper.html();
        expect(html).not.toContain('tablist');
        expect(html).not.toContain('role="tab"');
        expect(html).not.toContain('tabpanel');
    });

    // A radiogroup owes arrow-key roving. The nav is two ordinary buttons and claims no group role at all,
    // which is the same conclusion M117 reached for the settings rail.
    it('claims no radiogroup role it does not implement', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        expect(wrapper.html()).not.toContain('radiogroup');
    });
});
