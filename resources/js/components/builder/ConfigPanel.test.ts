import { mount } from '@vue/test-utils';
import { computed, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConfigPanel from './ConfigPanel.vue';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderEnums, LocalField, LocalSection, PaletteGroup } from './types';

/**
 * The config panel's switcher, after J4c moved it into `MdsTabs`.
 *
 * ── WHAT THIS FILE IS FOR, AND WHAT IT DELIBERATELY IS NOT ─────────────────────────────────────────────
 * `Tabs.test.ts` owns the widget: the roving tabindex, the arrow keys, the relationships. This owns the
 * ADOPTION — that the panel still computes the same tab set for a field and for a section, that switching
 * really swaps the body, and that the page ends up with EXACTLY ONE tablist. That last one is not a style
 * preference: thirteen end-to-end locators walk the tab role on the builder, four of them loops that click
 * every match, so a second tablist on this page breaks a suite that cannot run on the development host.
 *
 * The store is a hand-built double rather than a real `useBuilderStore` — `LogicRail.test.ts`'s precedent
 * and its reason: the real one owns fetch, undo/redo and a debounced persist queue, none of which this
 * component's switcher touches, and mounting it would make every case here a test of that instead.
 *
 * ── `useEntitlements` IS MOCKED, AND MUTABLY (M90) ─────────────────────────────────────────────────────
 * The panel gained a plan gate on Save-to-library. `useEntitlements` calls Inertia's `usePage()`, which
 * throws outside an Inertia app — `FormRowActions`/`index.test.ts` hit the same wall and mocked the
 * composable, which is the precedent followed here. The mock is mutable rather than a constant `true`
 * so the gate is EXERCISED rather than merely stepped around: a constant would let the `v-if` be deleted
 * and every case below would still pass.
 */
const mocks = vi.hoisted(() => ({
    // Mutable for the reason `forms/index.test.ts` states about `manageScopes`: a fixed value makes the
    // gated affordance either unmountable or ungatable in every case, and neither tests the gate.
    fieldLibrary: { value: true },
}));

vi.mock('@/composables/useEntitlements', () => ({
    useEntitlements: () => ({ feature: () => mocks.fieldLibrary.value }),
}));

const ENUMS: BuilderEnums = {
    required_modes: [
        { value: 'optional', label: 'Optional' },
        { value: 'required', label: 'Required' },
    ],
    indexed_data_types: [],
    validation_rule_types: [],
    comparison_operators: [],
};

/**
 * Two types, chosen so the tab set DISCRIMINATES rather than merely renders: `short_text` has no options
 * and no config editor, `single_select` has both. A double where every type behaved identically would let
 * a panel that ignored `has_options` entirely pass every case below.
 */
const PALETTE: PaletteGroup[] = [
    {
        category: 'text',
        label: 'Text',
        icon: 'type',
        types: [
            { value: 'short_text', label: 'Short text', advanced: false, has_options: false, config_editor: null },
            { value: 'single_select', label: 'Single select', advanced: false, has_options: true, config_editor: 'choices' },
        ],
    },
];

function field(overrides: Partial<LocalField> = {}): LocalField {
    return {
        id: 'fld-1',
        uid: 'f1',
        form_section_id: null,
        key: 'full_name',
        field_type: 'short_text',
        label: 'Full name',
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

function section(overrides: Partial<LocalSection> = {}): LocalSection {
    return {
        id: 'sec-1',
        uid: 's1',
        key: 'basics',
        label: 'Basics',
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

function makeStore(selected: { field?: LocalField | null; section?: LocalSection | null } = {}): BuilderStore {
    return {
        selectedField: computed(() => selected.field ?? null),
        selectedSection: computed(() => selected.section ?? null),
        saveError: ref<string | null>(null),
        saving: ref(false),
        librarySaved: ref<string | null>(null),
        enums: ENUMS,
        palette: PALETTE,
        sections: ref<LocalSection[]>([]),
        fields: ref<LocalField[]>([]),
        touch: () => undefined,
        moveFieldToSection: () => undefined,
        saveFieldToLibrary: () => Promise.resolve(),
    } as unknown as BuilderStore;
}

function mountPanel(store: BuilderStore) {
    return mount(ConfigPanel, { props: { store } });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ConfigPanel — the tab set it hands the shared widget', () => {
    it('offers the field tabs, adding Options only for a type that has them', () => {
        const plain = mountPanel(makeStore({ field: field() }));
        expect(plain.findAll('[role="tab"]').map((tab) => tab.text())).toEqual([
            'Basics',
            'Validation',
            'Advanced',
        ]);

        const choosable = mountPanel(makeStore({ field: field({ field_type: 'single_select' }) }));
        expect(choosable.findAll('[role="tab"]').map((tab) => tab.text())).toEqual([
            'Basics',
            'Options',
            'Validation',
            'Advanced',
        ]);
    });

    it('offers a section only Basics and Advanced', () => {
        const wrapper = mountPanel(makeStore({ section: section() }));

        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Basics', 'Advanced']);
    });

    it('renders no switcher at all when nothing is selected', () => {
        const wrapper = mountPanel(makeStore());

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Select a field or section to configure it.');
    });
});

describe('ConfigPanel — exactly one tablist, which is an end-to-end contract', () => {
    it('renders one tablist for a field and one for a section', () => {
        // ⭐ Thirteen locators across builder-axe, responsive-axe and personalization-axe walk the tab role
        // on the builder, four of them LOOPS that click every match. A second tablist anywhere on that page
        // gets its tabs clicked mid-scan and makes every settle locator resolve to whichever strip came
        // first in the DOM. DSR §3.4 forbids the pane switcher becoming one; this is the other half — the
        // panel must not grow a second strip of its own either.
        expect(mountPanel(makeStore({ field: field() })).findAll('[role="tablist"]')).toHaveLength(1);
        expect(mountPanel(makeStore({ section: section() })).findAll('[role="tablist"]')).toHaveLength(1);
    });

    it('names the tablist, rather than leaving the name on a wrapper', () => {
        // The name must land on the element carrying the role, not on MdsTabs' outer box. ⚠️ It is NOT a
        // guard against the kebab spelling: a mutation to `aria-label` at the call site left this green,
        // because Vue camelizes a hyphenated prop key and the prop is filled either way. `vue-tsc` is the
        // only thing that rejects that spelling, and the component's docblock now says so accurately.
        const wrapper = mountPanel(makeStore({ field: field() }));

        expect(wrapper.get('[role="tablist"]').attributes('aria-label')).toBe('Configuration sections');
    });
});

describe('ConfigPanel — switching a tab swaps the body', () => {
    it('shows the Basics editor first and the Advanced editor after a click', async () => {
        const wrapper = mountPanel(makeStore({ field: field() }));

        // Both directions asserted on both tabs, so neither half can pass vacuously: a body that never
        // rendered at all would satisfy either `not.toContain` on its own.
        expect(wrapper.text()).toContain('Help text');
        expect(wrapper.text()).not.toContain('Field key');

        const advanced = wrapper.findAll('[role="tab"]').at(-1);
        expect(advanced?.text()).toBe('Advanced');
        await advanced?.trigger('click');

        expect(wrapper.text()).toContain('Field key');
        expect(wrapper.text()).not.toContain('Help text');
    });

    it('follows the widget, so the selected tab and the rendered body cannot disagree', async () => {
        // The panel passes `activeTab` down and writes it back from the event. A version that rendered off
        // its own copy would show one tab selected and another tab's body — invisible to any assertion that
        // only reads one of the two.
        const wrapper = mountPanel(makeStore({ field: field({ field_type: 'single_select' }) }));
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[1]?.trigger('click');

        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.attributes('aria-selected'))).toEqual([
            'false',
            'true',
            'false',
            'false',
        ]);
        const labelledBy = wrapper.get('[role="tabpanel"]').attributes('aria-labelledby');
        expect(document.getElementById(labelledBy as string)?.textContent?.trim() ?? tabs[1]?.text()).toBe(
            'Options',
        );
    });
});

describe('ConfigPanel — the plan gate on Save-to-library (M90)', () => {
    afterEach(() => {
        mocks.fieldLibrary.value = true;
    });

    // ⛔ THE BUTTON LIVES ON THE **ADVANCED** TAB, and the first draft of these two cases did not open it.
    // Both passed: the positive one failed honestly, but the negative one PASSED VACUOUSLY — the button is
    // absent from the Basics body whatever the plan says. A negative assertion about something that is never
    // rendered in the case under test is not a gate, and it is the exact shape this repository keeps
    // recording. The helper below opens Advanced first so both arms read the same body.
    async function advancedBodyOf(entitled: boolean) {
        mocks.fieldLibrary.value = entitled;

        const wrapper = mountPanel(makeStore({ field: field({ field_type: 'short_text' }) }));
        const tabs = wrapper.findAll('[role="tab"]');

        // Basics · Validation · Advanced for a type with no options — asserted, not assumed, so a tab-set
        // change renames this test's target rather than silently pointing it at the wrong body.
        expect(tabs.map((tab) => tab.text())).toEqual(['Basics', 'Validation', 'Advanced']);
        await tabs[2]?.trigger('click');

        return wrapper;
    }

    it('renders the Save-to-library affordance when the plan includes the question library', async () => {
        expect((await advancedBodyOf(true)).text()).toContain('Save to library');
    });

    it('hides it when the plan does not, which is the one route a denied tenant could still reach', async () => {
        // This button was the ONLY `feature:`-gated surface in the application with no client gate, which
        // is why the backlog row that filed the server-side defect read as if it fired on every click.
        // The route refuses it either way; this spares the click, exactly as Builder.vue says at its own
        // call site for the Fields-to-Library toggle.
        expect((await advancedBodyOf(false)).text()).not.toContain('Save to library');
    });
});
