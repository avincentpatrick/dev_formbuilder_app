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

        // ⛔ THE REAL FACTORY SEEDS THE FIRST VISIBLE STEP ON EVERY BUILD, AND THE MOCK MUST TOO.
        // That reseed IS the defect `initialStepKey` exists to undo, so a mock that let the module ref
        // survive a rebuild would make the preservation case below pass with the feature deleted.
        currentStepKey.value = 's1';

        return {
            currentStepKey,
            visibleSteps: computed(() => STEPS.slice(0, stepCount.value)),
            currentStep: computed(
                () => STEPS.slice(0, stepCount.value).find((s) => s.key === currentStepKey.value) ?? null,
            ),
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

const STEPS = [
    { key: 's1', sectionKey: 's1', title: 'Section one', fieldKeys: ['q1'], isRepeat: false },
    { key: 's2', sectionKey: 's2', title: 'Section two', fieldKeys: ['q2'], isRepeat: false },
];

const currentStepKey = ref('s1');

/**
 * How many of {@link STEPS} the mocked engine currently publishes.
 *
 * ⛔ THE STEP COUNT HAS TO BE DRIVABLE OR THE STRIP'S OWN `v-if` IS UNTESTABLE. The mock reports a step
 * list the store cannot influence — deliberately, since the property under test here is a CALL COUNT
 * rather than a projection — so a case about a single-step form has no way to ask for one. This is the
 * smallest knob that makes that case real instead of vacuous.
 */
const stepCount = ref(2);

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
    sections: ReturnType<typeof ref<LocalSection[]>>;
    selected: () => Selection;
}

function makeStore(fields: LocalField[], sections: LocalSection[]): Double {
    const selection = ref<Selection>(null);
    const fieldsRef = ref(fields);
    const sectionsRef = ref(sections);

    const store = {
        fields: fieldsRef,
        sections: sectionsRef,
        selection,
        selectedField: computed(() => {
            const s = selection.value;

            return s?.kind === 'field' ? (fieldsRef.value.find((f) => f.uid === s.uid) ?? null) : null;
        }),
        select: (next: Selection) => (selection.value = next),
    } as unknown as BuilderStore;

    return { store, fields: fieldsRef, sections: sectionsRef, selected: () => selection.value };
}

const FORM_BASE = {
    id: 'form-1',
    title: 'Survey',
    description: null,
    default_locale: 'en',
    supported_locales: ['en'],
    // `M120` — the presentation mode reaches the projection through this prop, so it has to be a real key
    // here. Left off, every single-page case below would read `undefined`, render stepped, and pass for the
    // wrong reason.
    single_page_mode: false,
};

/** The `form` prop, with the presentation mode chosen per case. */
function formProp(singlePage = false) {
    return { ...FORM_BASE, single_page_mode: singlePage } as never;
}

const STUBS = { FieldRow: true, RepeatGroup: true };

function mountPane(double: Double, active = true, singlePage = false) {
    return mount(PreviewPane, {
        props: {
            store: double.store,
            form: formProp(singlePage),
            draft: { id: 'ver-1', version_number: 1 },
            active,
        },
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
    stepCount.value = 2;
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

describe('the section strip', () => {
    it('lists every step, index-prefixed and titled from the LIVE model', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        expect(wrapper.get('[data-preview-strip]').text()).toContain('1. S1');
        expect(wrapper.get('[data-preview-strip]').text()).toContain('2. S2');

        // ⛔ A RENAME MOVES NO SHAPE, so no engine is rebuilt and the frozen `RuntimeStep.title` still reads
        // "S2". A strip sourced from the runtime would show the old name here, directly above a heading
        // showing the new one. The engine call count is asserted alongside so this cannot pass by accident
        // of a rebuild nobody asked for.
        const sections = double.sections.value!;
        double.sections.value = [sections[0], { ...sections[1], label: 'Household roster' }];
        await flushPromises();

        expect(wrapper.get('[data-preview-strip]').text()).toContain('2. Household roster');
        expect(created).toHaveLength(1);
    });

    it('moves the preview to the chosen step', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        const radios = wrapper.get('[data-preview-strip]').findAll('input[type="radio"]');
        expect(radios).toHaveLength(2);

        await radios[1].setValue();
        await flushPromises();

        expect(wrapper.get('[data-section-heading]').text()).toBe('S2');
    });

    // ⛔ THE DEFECT THE STRIP MAKES VISIBLE, AND IT PREDATES THE STRIP. `PreviewRuntime` is keyed on the
    // engine shape, so a structural edit tears it down; `createFormRuntime` then seeds the first visible
    // step. Before M119 the preview therefore jumped back to page 1 roughly 300ms after any structural
    // edit, with nothing on screen to explain it. The mock reseeds on every build for exactly this reason.
    it('keeps the author on their step across an engine rebuild', async () => {
        const double = twoSections();
        const wrapper = mountPane(double, true);
        await flushPromises();

        await wrapper.get('[data-preview-strip]').findAll('input[type="radio"]')[1].setValue();
        await flushPromises();
        expect(wrapper.get('[data-section-heading]').text()).toBe('S2');

        double.fields.value = [...double.fields.value!, field('q3', { form_section_id: 'sec-s1', sequence: 2 })];
        await flushPromises();
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS);
        await flushPromises();

        expect(created).toHaveLength(2);
        expect(wrapper.get('[data-section-heading]').text()).toBe('S2');
    });

    // One step is not a choice, and a control offering it would be a control an author can only confirm.
    it('is absent when the form has a single step', async () => {
        stepCount.value = 1;
        const wrapper = mountPane(makeStore([field('q1')], []), true);
        await flushPromises();

        expect(wrapper.find('[data-preview-strip]').exists()).toBe(false);
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

    // ⛔ THIS CASE ASSERTS THE IMPLEMENTATION, NOT THE ABSENCE OF A STRING, AND THE DIFFERENCE IS WHY IT WAS
    // REWRITTEN. Until M119 the pane held no group at all, and `not.toContain('radiogroup')` said everything
    // there was to say. The section strip IS a radiogroup — and it would have passed that old assertion
    // VACUOUSLY, because `MdsSegmentedControl` takes its semantics from a native `<fieldset>` of radio
    // inputs and writes no role attribute for a substring check to find. So the string check is kept for the
    // one thing it can still refuse, a hand-rolled role with no roving behind it, and the radios themselves
    // are what prove the roving is really there.
    it('implements its radiogroup with native radios rather than claiming the role', async () => {
        const wrapper = mountPane(twoSections(), true);
        await flushPromises();

        const strip = wrapper.get('[data-preview-strip]');
        expect(strip.findAll('fieldset')).toHaveLength(1);
        expect(strip.findAll('input[type="radio"]')).toHaveLength(2);
        expect(wrapper.html()).not.toContain('role="radiogroup"');
    });

});


/**
 * One page or step by step (`R-f1332829`, `D35`, `D57`) — the half of `B8` that gave the column a writer.
 *
 * ⛔ THE PREVIEW HAD NO WAY TO KNOW THE MODE UNTIL THIS ROW. `BuilderPresenter` emitted no
 * `single_page_mode`, so the projection's own `?? false` decided and the preview was always stepped, which
 * `previewLimitations()` had to state out loud. These cases are what stop it regressing to that.
 */
describe('one page or step by step', () => {
    it('shows one step at a time when the form is stepped, which is the default', async () => {
        const wrapper = mountPane(twoSections());
        await flushPromises();

        expect(wrapper.findAll('[data-section]')).toHaveLength(1);
        expect(wrapper.get('[data-section]').attributes('data-section-key')).toBe('s1');
        // Non-vacuous: the second section's field has no node AT ALL in this mode, which is what makes the
        // one-page case below a real difference rather than a re-count of the same DOM.
        expect(wrapper.find('[data-preview-field="q2"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows every section in one scroll in one-page mode', async () => {
        const wrapper = mountPane(twoSections(), true, true);
        await flushPromises();

        const sections = wrapper.findAll('[data-section]');

        expect(sections).toHaveLength(2);
        expect(sections.map((s) => s.attributes('data-section-key'))).toEqual(['s1', 's2']);
        expect(wrapper.findAll('[data-section-heading]').map((h) => h.text())).toEqual(['S1', 'S2']);
        expect(wrapper.find('[data-preview-field="q1"]').exists()).toBe(true);
        expect(wrapper.find('[data-preview-field="q2"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('drops the strip and the Back/Next chrome in one-page mode, because there is nowhere to go', async () => {
        const wrapper = mountPane(twoSections(), true, true);
        await flushPromises();

        expect(wrapper.find('[data-preview-strip]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Page 1 of 2');
        expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Next');

        wrapper.unmount();
    });

    it('still renders through the SAME FieldRow, and selection works from the second section', async () => {
        // The reuse claim is the one `preview-model.ts` opens with: one component set, both modes. Selection
        // is driven from `q2`, which exists at all only in one-page mode.
        const double = twoSections();
        const wrapper = mountPane(double, true, true);
        await flushPromises();

        expect(wrapper.findAllComponents({ name: 'FieldRow' })).toHaveLength(2);

        await wrapper.get('[data-preview-field="q2"]').trigger('click');
        expect(double.selected()).not.toBeNull();

        wrapper.unmount();
    });

    it('honours a mode change with NO engine rebuild, which IS the mechanism', async () => {
        // ⛔ THIS IS THE ONLY CASE HERE THAT CAN TELL THE TWO SOURCES APART, AND IT IS WHY IT EXISTS.
        // `runtime.singlePageMode` is a plain boolean captured once inside `createFormRuntime`, and
        // `shapeOf()` reads `sections`/`fields` and never `form` — so an engine-sourced read means a toggle
        // moves no shape, remounts nothing, and leaves the OLD mode on screen until an unrelated structural
        // edit. Reading the live render model needs no rebuild at all, so BOTH halves are asserted: the
        // sections appear, AND `created` does not grow.
        //
        // ⛔ MEASURED WITH TWO MUTATIONS, BECAUSE THE FIRST ONE PROVES LESS THAN IT LOOKS. Reverting the read
        // to `runtime.singlePageMode` reddens FOUR cases here — but only because this mock returns no
        // `singlePageMode` at all, so the mutant reads `undefined` and every mode is stepped. That catches
        // ABSENCE, not staleness. Adding `singlePageMode: schema.form.single_page_mode` to the mock, so the
        // engine reads the flag correctly at build time, leaves exactly THIS case failing and the other 29
        // green. That is the measurement worth keeping: this case is the only one in the suite that can
        // catch a mode which was right when the engine was built and wrong afterwards.
        //
        // ⚠️ AND THE CLOCK IS ADVANCED BEFORE `created` IS RE-READ, WHICH IS WHAT MAKES "no rebuild" A
        // MEASUREMENT. This file's header records three mutants surviving an assertion that advanced a clock
        // with nothing on it; asserting a rebuild did NOT happen without ever running the timer that would
        // have performed it is the same vacuity wearing the opposite sign.
        const wrapper = mountPane(twoSections());
        await flushPromises();

        expect(wrapper.findAll('[data-section]')).toHaveLength(1);

        const builds = created.length;

        await wrapper.setProps({ form: formProp(true) });
        await flushPromises();
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS * 4);
        await flushPromises();

        expect(wrapper.findAll('[data-section]')).toHaveLength(2);
        expect(created).toHaveLength(builds);

        wrapper.unmount();
    });
});
