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

/**
 * ⛔ THIS FIXTURE WAS INERT UNTIL M116 AND THAT IS WHY NOTHING HERE HAD EVER RENDERED A RULE ROW.
 * `required_modes` held two of three modes and both rule vocabularies were empty, so the Basics tab could
 * not reach the Conditional branch and the Validation tab had nothing to offer. Every member added below is
 * made LOAD-BEARING by an assertion rather than by a type annotation: `tsconfig.json` excludes every test
 * file, so a required member added to these interfaces rots to `undefined` here and no gate sees it — which
 * is exactly how M115 shipped a panel whose rule filter silently offered nothing.
 *
 * `min_length` is present so the restriction on the reveal has something real to exclude, and `skip_if` so
 * `governs_requiredness` discriminates rather than merely renders: it reads an operator and names a field
 * like the two required rules, and differs only in the flag the partition turns on.
 */
const ENUMS: BuilderEnums = {
    required_modes: [
        { value: 'optional', label: 'Optional' },
        { value: 'required', label: 'Required' },
        { value: 'conditional', label: 'Conditional' },
    ],
    indexed_data_types: [],
    validation_rule_types: [
        { value: 'min_length', label: 'Minimum length', shapes: ['text'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false, governs_requiredness: false },
        { value: 'required_if', label: 'Required when a condition holds', shapes: ['text', 'choice'], takes_operator: true, takes_related_field: true, operator_may_be_empty: false, governs_requiredness: true },
        { value: 'required_with', label: 'Required with another question', shapes: ['text', 'choice'], takes_operator: true, takes_related_field: true, operator_may_be_empty: true, governs_requiredness: true },
        { value: 'skip_if', label: 'Skipped when a condition holds', shapes: ['text', 'choice'], takes_operator: true, takes_related_field: true, operator_may_be_empty: false, governs_requiredness: false },
    ],
    comparison_operators: [
        { value: 'eq', label: 'equals (=)', shapes: ['text', 'choice'] },
        { value: 'is_null', label: 'is blank', shapes: ['text', 'choice'] },
    ],
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
            { value: 'short_text', label: 'Short text', advanced: false, has_options: false, config_editor: null, value_shape: 'text' },
            // M130 — `appearances` is what makes the Options tab's choice layout LOAD-BEARING here; `short_text` keeps
            // none, so it reads as "no layout setting", the absent-member path a hand-built palette relies on.
            {
                value: 'single_select',
                label: 'Single select',
                advanced: false,
                has_options: true,
                config_editor: 'choices',
                value_shape: 'choice',
                appearances: [
                    { value: 'columns-pack', label: 'Side by side' },
                    { value: 'columns', label: 'In columns' },
                ],
            },
            // M115 — a type that carries NO ANSWER, so the Validation tab must not exist for it. It is in
            // the fixture rather than only in a comment because that is what makes value_shape LOAD-BEARING
            // here: no type-checker reads this file (tsconfig excludes **/*.test.ts), so a required member
            // added to PaletteType would otherwise rot silently to undefined.
            { value: 'note', label: 'Note', advanced: false, has_options: false, config_editor: 'content', value_shape: 'no_answer' },
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

/**
 * ⚠️ `fields` IS NOT DECORATION SINCE M116. The panel derives `comparableFields` from it, and the operator
 * control on a conditional rule is DISABLED until the compared question resolves to a shape — so a store
 * double with an empty field list silently makes that control unusable, and a case that drives it passes
 * vacuously or fails for the wrong reason. It defaults to empty because every pre-M116 case wants that.
 */
function makeStore(
    selected: {
        field?: LocalField | null;
        section?: LocalSection | null;
        fields?: LocalField[];
        errors?: Record<string, Record<string, string[]>>;
        touch?: (uid: string, kind: string) => void;
    } = {},
): BuilderStore {
    return {
        formId: 'form-1',
        selectedField: computed(() => selected.field ?? null),
        selectedSection: computed(() => selected.section ?? null),
        saveError: ref<string | null>(null), saveFieldErrors: ref<Record<string, Record<string, string[]>>>(selected.errors ?? {}),
        saving: ref(false),
        librarySaved: ref<string | null>(null),
        enums: ENUMS,
        palette: PALETTE,
        sections: ref<LocalSection[]>([]),
        fields: ref<LocalField[]>(selected.fields ?? []),
        touch: selected.touch ?? (() => undefined),
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

    it('gives a field that carries no answer no Validation tab at all', () => {
        // M115. ValueShape::allows() refuses every rule type for no_answer, and since M113 the publish gate
        // refuses them too — so offering the tab on a note was a route to a form that could not be
        // published. The negative control is the case above: short_text keeps its tab.
        // M129: a note gained the Content tab, between Basics and Advanced, from `FieldType::configEditor()`.
        const note = mountPanel(makeStore({ field: field({ field_type: 'note' }) }));

        expect(note.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Basics', 'Content', 'Advanced']);
    });

    it("mounts a note's content editor on the Content tab, and an emptied list removes the key rather than saving []", async () => {
        // M129, `R-6dedc3a9`. The absence is what every reader of the config treats as "no blocks"; an empty list
        // would be a second spelling of the same thing.
        const touch = vi.fn();
        const note = field({ field_type: 'note', config: { content: [{ type: 'divider' }], other: 1 } });
        const wrapper = mountPanel(makeStore({ field: note, touch }));

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Content')!.trigger('click');
        const editor = wrapper.findComponent({ name: 'ContentBlocksEditor' });
        expect(editor.props('blocks')).toEqual([{ type: 'divider' }]);
        expect(editor.props('formId')).toBe('form-1');

        editor.vm.$emit('update:blocks', [{ type: 'divider' }, { type: 'heading', level: 2, text: 'Next' }]);
        expect(note.config).toEqual({ content: [{ type: 'divider' }, { type: 'heading', level: 2, text: 'Next' }], other: 1 });

        editor.vm.$emit('update:blocks', []);
        expect(note.config).toEqual({ other: 1 });
        expect(touch).toHaveBeenCalledTimes(2);
        expect(touch).toHaveBeenLastCalledWith('f1', 'field');
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

describe('ConfigPanel — the choice layout that replaced the free-text appearance hint (M130)', () => {
    async function openTab(wrapper: ReturnType<typeof mountPanel>, name: string): Promise<void> {
        await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === name)!.trigger('click');
    }

    function layoutSelect(wrapper: ReturnType<typeof mountPanel>) {
        const label = wrapper.findAll('label').find((node) => node.text().includes('Choice layout'));
        expect(label, 'no "Choice layout" control on this tab').toBeDefined();
        return wrapper.find(`#${label!.attributes('for')}`);
    }

    it('offers the transmitted layouts on the Options tab, defaulting to one per line', async () => {
        const wrapper = mountPanel(makeStore({ field: field({ field_type: 'single_select' }) }));
        await openTab(wrapper, 'Options');

        const select = layoutSelect(wrapper);
        expect(select.findAll('option').map((option) => option.text())).toEqual(['One per line (default)', 'Side by side', 'In columns']);
        expect((select.element as HTMLSelectElement).value).toBe('');
    });

    it('writes the chosen layout, and null for the default', async () => {
        const touch = vi.fn();
        const choice = field({ field_type: 'single_select' });
        const wrapper = mountPanel(makeStore({ field: choice, touch }));
        await openTab(wrapper, 'Options');

        await layoutSelect(wrapper).setValue('columns');
        expect(choice.appearance).toBe('columns');

        await layoutSelect(wrapper).setValue('');
        expect(choice.appearance).toBeNull();
        expect(touch).toHaveBeenCalledTimes(2);
        expect(touch).toHaveBeenLastCalledWith('f1', 'field');
    });

    it('shows a stored value outside the list as kept, selected, and never as an editable string', async () => {
        // An XLSForm import's `likert`: the save request accepts it only unchanged, so the panel offers keep or replace.
        const wrapper = mountPanel(makeStore({ field: field({ field_type: 'single_select', appearance: 'likert' }) }));
        await openTab(wrapper, 'Options');

        const select = layoutSelect(wrapper);
        expect(select.findAll('option').map((option) => option.text())).toContain('Kept from an earlier setting or an import: likert');
        expect((select.element as HTMLSelectElement).value).toBe('likert');
    });

    it('shows a type with no layout setting its stored appearance in Advanced, with a way to remove it', async () => {
        const touch = vi.fn();
        const text = field({ appearance: 'numbers' });
        const wrapper = mountPanel(makeStore({ field: text, touch }));
        await openTab(wrapper, 'Advanced');

        const kept = wrapper.find('[data-kept-appearance]');
        expect(kept.exists()).toBe(true);
        expect(kept.text()).toContain('numbers');
        expect(kept.text()).toContain('does not change how this form looks');

        await kept.findAll('button').find((button) => button.text() === 'Remove appearance')!.trigger('click');
        expect(text.appearance).toBeNull();
        expect(touch).toHaveBeenCalledWith('f1', 'field');
    });

    it("tells the author a note's label stops reaching respondents once the note has content (M130, D69)", () => {
        const note = mountPanel(makeStore({ field: field({ field_type: 'note' }) }));
        const text = mountPanel(makeStore({ field: field() }));

        expect(note.text()).toContain('Respondents see this label only while the note has no content.');
        expect(text.text()).not.toContain('Respondents see this label only while the note has no content.');
    });

    it('offers no free-text appearance anywhere, and nothing at all for a type with no layout and no stored value', async () => {
        const wrapper = mountPanel(makeStore({ field: field() }));
        await openTab(wrapper, 'Advanced');

        expect(wrapper.text()).not.toContain('Appearance hint');
        expect(wrapper.find('[data-kept-appearance]').exists()).toBe(false);
        expect(wrapper.findAll('label').some((node) => node.text().includes('Choice layout'))).toBe(false);
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

/*
 * Increment M116 — "Required when…" on the Basics tab.
 *
 * Choosing Conditional used to write a setting no engine acted on: `requiredState()` honours the mode only
 * through a required_* rule, and with none the field falls out as optional in silence. These cases pin the
 * reveal, and — the one that matters — that it cannot delete the rules the Validation tab owns.
 */

function validation(overrides: Partial<BuilderValidation> = {}): BuilderValidation {
    return {
        rule_type: 'min_length',
        operator: null,
        rule_value: null,
        expression: null,
        error_message: null,
        related_field_key: null,
        sequence: 0,
        ...overrides,
    };
}

describe('ConfigPanel — the Conditional requiredness reveal (M116)', () => {
    it.each([
        ['optional', 'Optional'],
        ['required', 'Required'],
    ])('shows no condition editor when requiredness is %s', (mode) => {
        const wrapper = mountPanel(makeStore({ field: field({ is_required: mode }) }));

        expect(wrapper.find('[aria-label="Rule 1 check"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Required when');
    });

    it('reveals a complete condition row on Basics when requiredness is Conditional', () => {
        const wrapper = mountPanel(
            makeStore({
                field: field({
                    is_required: 'conditional',
                    validations: [validation({ rule_type: 'required_if', related_field_key: 'age' })],
                }),
            }),
        );

        expect(wrapper.text()).toContain('Required when');
        expect(wrapper.find('[aria-label="Rule 1 check"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Rule 1 compared field"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Rule 1 operator"]').exists()).toBe(true);
        // ⚠️ And NOT the mode switch: a raw expression cannot belong to this partition.
        expect(wrapper.find('[aria-label="Rule 1 type"]').exists()).toBe(false);
    });

    it('adds no second tablist and no fourth tab', () => {
        // ⛔ THE ONE-TABLIST INVARIANT. Thirteen e2e locators walk `[role="tab"]` on this page, so a revealed
        // sub-panel that introduced its own strip would break them all. It holds by construction here — the
        // reveal renders only selects, inputs and buttons — and "by construction" is precisely what this
        // asserts, because the next person to reach for a sub-tabbed editor will not know that.
        const wrapper = mountPanel(makeStore({ field: field({ is_required: 'conditional' }) }));

        expect(wrapper.findAll('[role="tablist"]')).toHaveLength(1);
        expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Basics', 'Validation', 'Advanced']);
    });

    it('edits only the rules it owns and leaves every other rule byte-identical at its own index', () => {
        // ⛔ THE CLOBBER CASE, AND IT IS THE REASON THE PARTITION EXISTS. `ValidationEditor` emits a WHOLE
        // fresh array, so wiring this instance straight to `setValidations` would replace three rules with
        // one and silently delete the author's pattern and skip rules. Asserting the row COUNT on Basics
        // would not catch that — the count is right in both worlds — so this reads the field object back and
        // compares the untouched rows by value AND by index.
        const target = field({
            is_required: 'conditional',
            validations: [
                validation({ rule_type: 'required_if', related_field_key: 'age', sequence: 0 }),
                validation({ rule_type: 'min_length', rule_value: '3', sequence: 1 }),
                validation({ rule_type: 'skip_if', related_field_key: 'age', operator: 'eq', rule_value: 'no', sequence: 2 }),
            ],
        });
        const untouched = [
            { ...target.validations[1] },
            { ...target.validations[2] },
        ];

        // ⚠️ `age` must exist as a sibling field or the operator control stays DISABLED — it is gated on the
        // compared question resolving to a shape — and this case would pass without ever driving an edit.
        const wrapper = mountPanel(
            makeStore({ field: target, fields: [target, field({ id: 'fld-2', uid: 'f2', key: 'age' })] }),
        );

        // Exactly one row is shown here: the required_if. The min_length and skip_if belong to the other tab.
        expect(wrapper.findAll('[aria-label="Rule 1 check"]')).toHaveLength(1);
        expect(wrapper.find('[aria-label="Rule 2 check"]').exists()).toBe(false);

        wrapper.get('[aria-label="Rule 1 operator"]').setValue('eq');

        expect(target.validations).toHaveLength(3);
        expect(target.validations[0].operator).toBe('eq');
        expect(target.validations[0].rule_type).toBe('required_if');
        expect(target.validations[1]).toEqual(untouched[0]);
        expect(target.validations[2]).toEqual(untouched[1]);
    });

    it('shows a rule added on Basics on the Validation tab as well, because there is one array', async () => {
        const target = field({ is_required: 'conditional' });
        const wrapper = mountPanel(makeStore({ field: target }));

        wrapper.findAll('button').find((b) => b.text() === 'Add condition')!.trigger('click');
        expect(target.validations).toHaveLength(1);
        expect(target.validations[0].rule_type).toBe('required_if');

        await wrapper.findAll('[role="tab"]').find((t) => t.text() === 'Validation')!.trigger('click');

        expect(wrapper.find('[aria-label="Rule 1 check"]').exists()).toBe(true);
        // On the Validation tab the mode switch IS offered — the same row, its full editor.
        expect(wrapper.find('[aria-label="Rule 1 type"]').exists()).toBe(true);
    });

    it('offers requiredness on a note but reveals no editor, because no rule can apply to it', () => {
        // ⚠️ THE CONTROL STAYS VISIBLE DELIBERATELY. Hiding it would strand a note already marked Required
        // with no way to repair it; the publish gate refuses that field and names the repair instead.
        const wrapper = mountPanel(
            makeStore({ field: field({ field_type: 'note', is_required: 'conditional' }) }),
        );

        expect(wrapper.text()).toContain('Requiredness');
        expect(wrapper.find('[aria-label="Rule 1 check"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Required when');
    });
});

describe('ConfigPanel — a refused save marks the field (M128, R-d001de0c)', () => {
    it('marks the refused control in place, tied to its message for assistive technology', () => {
        const target = field();
        const wrapper = mountPanel(makeStore({ field: target, errors: { [target.uid]: { label: ['The label field is required.'] } } }));

        const input = wrapper.find('input[aria-invalid="true"]');
        expect(input.exists()).toBe(true);
        expect(input.element).toBe(wrapper.findAll('input').find((i) => (i.element as HTMLInputElement).value === target.label)!.element);

        const described = (input.attributes('aria-describedby') ?? '').split(' ');
        const message = described.map((id) => wrapper.find(`[id="${id}"]`)).find((el) => el.exists() && el.text().includes('The label field is required.'));
        expect(message).toBeDefined();
    });

    it('lists a refusal with no control of its own in the alert, in the panel\'s words', () => {
        const target = field();
        const wrapper = mountPanel(makeStore({ field: target, errors: { [target.uid]: { 'validations.0.rule_value': ['The rule value must be a number.'] } } }));

        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Rule 1: The rule value must be a number.');
        expect(wrapper.find('[aria-invalid="true"]').exists()).toBe(false);
    });

    it('says which OTHER question still holds a refused change, so moving on cannot hide it', () => {
        const selected = field();
        const other = field({ uid: 'f2', id: 'fld-2', key: 'age', label: 'Age' });
        const wrapper = mountPanel(makeStore({ field: selected, fields: [selected, other], errors: { f2: { label: ['Bad.'] } } }));

        expect(wrapper.find('[role="alert"]').text()).toContain('“Age” has a change that was not saved. Select it to see why.');
        expect(wrapper.find('[aria-invalid="true"]').exists()).toBe(false);
    });

    it('marks nothing and raises no alert when nothing was refused', () => {
        const wrapper = mountPanel(makeStore({ field: field() }));

        expect(wrapper.find('[aria-invalid="true"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });
});
