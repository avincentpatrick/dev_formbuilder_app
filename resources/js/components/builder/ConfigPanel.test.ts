import { mount } from '@vue/test-utils';
import { computed, ref } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
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
 */

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
