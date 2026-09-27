/**
 * The validation editor's offer — Increment M115, closing the editor half of `R-e878d49a`.
 *
 * ⛔ THIS COMPONENT HAD NO TEST FILE AT ALL BEFORE THIS ONE, and that is why the defect it fixes could
 * ship. Its only mount was through `ConfigPanel.test.ts`, which never clicks the Validation tab, and whose
 * enum fixture shipped `validation_rule_types: []` and `comparison_operators: []` — so no case in the
 * repository had ever rendered a rule row.
 *
 * ⚠️ WHAT THIS FILE GATES AND WHAT IT DOES NOT. It gates the component's BEHAVIOUR given a payload: which
 * controls appear, which options each offers, and what it emits. Whether the payload's `shapes` are the
 * right shapes is `tests/Feature/Forms/BuilderEnumsPayloadTest.php`'s job, asserted there against an
 * independent census — so the fixtures below are deliberately a small, representative subset rather than a
 * transcription of the server's eleven rule types.
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ValidationEditor from './ValidationEditor.vue';
import type { BuilderValidation, ComparableField, OperatorOption, RuleTypeOption } from './types';

const RULE_TYPES: RuleTypeOption[] = [
    { value: 'min_length', label: 'Minimum length', shapes: ['text'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false },
    { value: 'pattern', label: 'Must match a pattern', shapes: ['text'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false },
    { value: 'min_value', label: 'Minimum value', shapes: ['number', 'duration', 'scale'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false },
    { value: 'greater_than_field', label: 'Greater than another question', shapes: ['number', 'duration'], takes_operator: false, takes_related_field: true, operator_may_be_empty: false },
    { value: 'required_if', label: 'Required when a condition holds', shapes: ['text', 'number', 'temporal'], takes_operator: true, takes_related_field: true, operator_may_be_empty: false },
    { value: 'required_with', label: 'Required with another question', shapes: ['text', 'number', 'temporal'], takes_operator: true, takes_related_field: true, operator_may_be_empty: true },
];

const OPERATORS: OperatorOption[] = [
    { value: 'gt', label: 'greater than (>)', shapes: ['number', 'duration', 'scale'] },
    { value: 'gte', label: 'at least (≥)', shapes: ['number', 'duration', 'scale'] },
    { value: 'eq', label: 'equals (=)', shapes: ['text', 'number', 'temporal', 'choice'] },
    { value: 'is_null', label: 'is blank', shapes: ['text', 'number', 'temporal', 'choice'] },
    { value: 'contains', label: 'contains', shapes: ['text', 'choice'] },
];

const FIELDS: ComparableField[] = [
    { key: 'age', label: 'Your age', value_shape: 'number' },
    { key: 'visit_date', label: 'Date of visit', value_shape: 'temporal' },
    { key: 'notes', label: 'Notes', value_shape: 'text' },
];

function row(overrides: Partial<BuilderValidation> = {}): BuilderValidation {
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

function mountEditor(valueShape: string, validations: BuilderValidation[] = []) {
    return mount(ValidationEditor, {
        props: { validations, ruleTypes: RULE_TYPES, operators: OPERATORS, valueShape, comparableFields: FIELDS },
    });
}

function control(wrapper: ReturnType<typeof mountEditor>, label: string) {
    return wrapper.get(`[aria-label="${label}"]`);
}

function optionLabels(wrapper: ReturnType<typeof mountEditor>, label: string): string[] {
    return control(wrapper, label)
        .findAll('option')
        .map((option) => option.text());
}

describe('ValidationEditor — which rules it offers', () => {
    it('offers a text field the length and format rules and never the numeric ones', () => {
        const wrapper = mountEditor('text', [row()]);

        const offered = optionLabels(wrapper, 'Rule 1 check');

        expect(offered).toContain('Minimum length');
        expect(offered).toContain('Must match a pattern');
        expect(offered).not.toContain('Minimum value');
        expect(offered).not.toContain('Greater than another question');
    });

    it('offers a date field the conditional family and no constraint at all', () => {
        // ⛔ THE MEASURED REASON, not tidiness: `min_value` on a temporal answer fails CLOSED — every
        // non-empty answer becomes invalid and the field is unanswerable — which is why M113's publish gate
        // refuses it and why offering it here was offering an unpublishable form.
        const wrapper = mountEditor('temporal', [row({ rule_type: 'required_if' })]);

        const offered = optionLabels(wrapper, 'Rule 1 check');

        expect(offered).toContain('Required when a condition holds');
        expect(offered).toContain('Required with another question');
        expect(offered).not.toContain('Minimum value');
        expect(offered).not.toContain('Minimum length');
    });

    it('cannot add a rule at all for a field whose shape allows none', () => {
        const wrapper = mountEditor('no_answer');
        const add = wrapper.findAll('button').find((b) => b.text() === 'Add rule');

        expect(add?.attributes('disabled')).toBeDefined();
    });
});

describe('ValidationEditor — the operator, which only four rules read', () => {
    it('renders no operator control for a constraint rule', () => {
        // The `operator` column is never read for `pattern`: both lowerings throw on it. A control that
        // changes nothing is worse than no control, because it reads as a setting.
        const wrapper = mountEditor('text', [row({ rule_type: 'pattern' })]);

        expect(wrapper.find('[aria-label="Rule 1 operator"]').exists()).toBe(false);
    });

    it('renders the operator and the compared-field controls for a conditional rule', () => {
        // ⛔ THE REGRESSION THIS FIXES. The compared-field control used to be gated on a client literal
        // naming the two field-comparison rules only, so `required_if` had nowhere to say WHICH question —
        // it saved, published, and then threw at evaluation.
        const wrapper = mountEditor('text', [row({ rule_type: 'required_if' })]);

        expect(wrapper.find('[aria-label="Rule 1 compared field"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Rule 1 operator"]').exists()).toBe(true);
    });

    it('holds the operator closed until a question is named, because the compared field decides the list', () => {
        const wrapper = mountEditor('text', [row({ rule_type: 'required_if' })]);
        const operator = control(wrapper, 'Rule 1 operator');

        expect(operator.attributes('disabled')).toBeDefined();
        expect(optionLabels(wrapper, 'Rule 1 operator')).toEqual(['Choose a question first']);
    });

    it('offers the ordered operators against a number and withholds them against a date', () => {
        // ⛔ THE PREMISE CORRECTION THIS INCREMENT MADE. The operator compares the RELATED field's value,
        // so the shape that governs is the compared question's — not the field the rule is attached to.
        // Both rows below hang off the same text field.
        const numeric = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'age' })]);
        const temporal = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'visit_date' })]);

        expect(optionLabels(numeric, 'Rule 1 operator')).toContain('at least (≥)');
        expect(optionLabels(temporal, 'Rule 1 operator')).not.toContain('at least (≥)');
        // Equality is value-agnostic, so it survives on both — the negative case is not vacuous.
        expect(optionLabels(temporal, 'Rule 1 operator')).toContain('equals (=)');
    });

    it('labels the operators in words with their symbol, never as Gt or Lte', () => {
        const wrapper = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'age' })]);
        const offered = optionLabels(wrapper, 'Rule 1 operator');

        expect(offered).toContain('greater than (>)');
        expect(offered.join(' ')).not.toContain('Gt');
        expect(offered.join(' ')).not.toContain('Gte');
    });

    it('offers "is answered" only where an absent operator is itself a condition', () => {
        // `required_with` with no operator lowers to `isNotNull(related)`. The same absence on `required_if`
        // THROWS at evaluation, so it is offered as a prompt to choose rather than as a usable state.
        const withRule = mountEditor('text', [row({ rule_type: 'required_with', related_field_key: 'age' })]);
        const ifRule = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'age' })]);

        expect(optionLabels(withRule, 'Rule 1 operator')).toContain('is answered');
        expect(optionLabels(ifRule, 'Rule 1 operator')).not.toContain('is answered');
        expect(optionLabels(ifRule, 'Rule 1 operator')).toContain('Choose a comparison');
    });
});

describe('ValidationEditor — what a row still asks for', () => {
    it('asks for a value where the rule compares against a literal', () => {
        const wrapper = mountEditor('text', [row({ rule_type: 'min_length' })]);

        expect(wrapper.find('[aria-label="Rule 1 value"]').exists()).toBe(true);
    });

    it('asks for no value where the rule compares two fields, or asks nothing of one', () => {
        const fieldToField = mountEditor('number', [row({ rule_type: 'greater_than_field', related_field_key: 'age' })]);
        const blankness = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'age', operator: 'is_null' })]);

        expect(fieldToField.find('[aria-label="Rule 1 value"]').exists()).toBe(false);
        expect(blankness.find('[aria-label="Rule 1 value"]').exists()).toBe(false);
    });
});

describe('ValidationEditor — a rule the field can no longer take', () => {
    it('keeps a saved rule visible and says why it is unavailable', () => {
        // ⛔ `MdsSelect` IS A NATIVE <select> BOUND WITH `:value`, so a model value absent from the options
        // renders BLANK — the author would be looking at an empty dropdown and editing a rule they cannot
        // see. Measured before this branch was written, not assumed.
        const wrapper = mountEditor('text', [row({ rule_type: 'min_value', rule_value: '5' })]);

        const offered = optionLabels(wrapper, 'Rule 1 check');

        expect(offered[0]).toBe('Minimum value — not available for this question');
        expect(control(wrapper, 'Rule 1 check').findAll('option')[0].attributes('disabled')).toBeDefined();
        // And the rest of the list is still the field's own rules, so the author can fix it in one move.
        expect(offered).toContain('Minimum length');
    });

    it('keeps a compared field that has since been deleted visible rather than silently dropping it', () => {
        const wrapper = mountEditor('text', [row({ rule_type: 'required_if', related_field_key: 'gone_away' })]);

        // Index 1, not 0: MdsSelect renders its own placeholder as a disabled first option.
        const offered = optionLabels(wrapper, 'Rule 1 compared field');

        expect(offered).toContain('gone_away (deleted)');
        expect(control(wrapper, 'Rule 1 compared field').findAll('option')[1].attributes('disabled')).toBeDefined();
        expect(offered).toContain('Your age');
    });
});

describe('ValidationEditor — what it emits', () => {
    it('clears the columns the new rule kind does not read when the check changes', () => {
        // Leaving them would persist an operator on a `pattern` row and a related field on a `min_length`
        // one — columns no lowering reads, which the next person to open the row would read as intent.
        const wrapper = mountEditor('text', [
            row({ rule_type: 'required_if', related_field_key: 'age', operator: 'gt', rule_value: '18' }),
        ]);

        control(wrapper, 'Rule 1 check').setValue('min_length');

        const emitted = wrapper.emitted('update:validations');
        expect(emitted).toBeTruthy();

        const next = (emitted as BuilderValidation[][][])[0][0][0];
        expect(next.rule_type).toBe('min_length');
        expect(next.operator).toBeNull();
        expect(next.related_field_key).toBeNull();
        // The literal survives: it is the one column the new rule still reads.
        expect(next.rule_value).toBe('18');
    });

    it('keeps the operator when both kinds read one', () => {
        const wrapper = mountEditor('text', [
            row({ rule_type: 'required_if', related_field_key: 'age', operator: 'gt' }),
        ]);

        control(wrapper, 'Rule 1 check').setValue('required_with');

        const next = (wrapper.emitted('update:validations') as BuilderValidation[][][])[0][0][0];
        expect(next.operator).toBe('gt');
        expect(next.related_field_key).toBe('age');
    });

    it('seeds a new rule from what the field can actually take', () => {
        // `addRule()` used to seed `ruleTypes[0]`, which is `min_value` — a rule a text field cannot take,
        // so every new rule on a text field arrived already unpublishable.
        const wrapper = mountEditor('text');

        wrapper.findAll('button').find((b) => b.text() === 'Add rule')?.trigger('click');

        const next = (wrapper.emitted('update:validations') as BuilderValidation[][][])[0][0][0];
        expect(next.rule_type).toBe('min_length');
    });
});

/*
 * Increment M116 — the same editor, restricted to one family of rules.
 *
 * The Basics tab's "Required when…" reveal mounts a SECOND instance of this component rather than a second
 * editor, because the row it needs is byte-identical to the Validation tab's minus the mode switch. These
 * cases pin the three props that make that possible, and the one that must stay inert for the first caller.
 */

const REQUIRED_FAMILY = ['required_if', 'required_with'];

function mountRestricted(valueShape: string, validations: BuilderValidation[] = [], restrictToRuleTypes = REQUIRED_FAMILY) {
    return mount(ValidationEditor, {
        props: {
            validations,
            ruleTypes: RULE_TYPES,
            operators: OPERATORS,
            valueShape,
            comparableFields: FIELDS,
            restrictToRuleTypes,
            addLabel: 'Add condition',
            emptyText: 'No condition yet — this question stays optional until you add one.',
        },
    });
}

describe('ValidationEditor — restricted to one family (M116)', () => {
    it('offers only the restricted rules, and not a rule the shape would otherwise allow', () => {
        // ⚠️ `Minimum length` IS THE NON-VACUOUS HALF. The `text` shape allows it, so its absence proves the
        // restriction is doing the work rather than the shape filter that was already there.
        const wrapper = mountRestricted('text', [row({ rule_type: 'required_if' })]);

        expect(optionLabels(wrapper, 'Rule 1 check')).toEqual([
            'Required when a condition holds',
            'Required with another question',
        ]);
        expect(optionLabels(wrapper, 'Rule 1 check')).not.toContain('Minimum length');
    });

    it('seeds a new row from the restricted list, not from the first rule the shape allows', () => {
        const wrapper = mountRestricted('text');

        wrapper.findAll('button').find((b) => b.text() === 'Add condition')!.trigger('click');

        const emitted = wrapper.emitted('update:validations')![0][0] as BuilderValidation[];
        expect(emitted[0].rule_type).toBe('required_if');
    });

    it('hides the expression switch when restricted, keeps it when not, and keeps the remove button in both', () => {
        // ⛔ BOTH HALVES IN ONE CASE SO NEITHER IS VACUOUS. An expression row carries `rule_type: null` and
        // could never belong to a restricted partition, so offering the switch would let an author move a
        // row out of the surface that owns it into nothing. The remove button shares the row head with that
        // select, which is why gating the head instead of the select is the mistake this case catches.
        const restricted = mountRestricted('text', [row({ rule_type: 'required_if' })]);
        expect(restricted.find('[aria-label="Rule 1 type"]').exists()).toBe(false);
        expect(restricted.find('[aria-label="Remove rule 1"]').exists()).toBe(true);

        const open = mountEditor('text', [row({ rule_type: 'required_if' })]);
        expect(open.find('[aria-label="Rule 1 type"]').exists()).toBe(true);
        expect(open.find('[aria-label="Remove rule 1"]').exists()).toBe(true);
    });

    it('disables adding when the restriction and the shape intersect to nothing', () => {
        // `greater_than_field` is allowed on `number`, but it governs no requiredness — so a reveal
        // restricted to the required family on a shape whose required rules are excluded has nothing to add.
        const wrapper = mountRestricted('number', [], ['greater_than_field_that_does_not_exist']);

        const add = wrapper.findAll('button').find((b) => b.text() === 'Add condition')!;
        expect(add.attributes('disabled')).toBeDefined();
    });

    it('uses the supplied wording, while the default caller keeps the original strings', () => {
        const restricted = mountRestricted('text');
        expect(restricted.text()).toContain('No condition yet');
        expect(restricted.findAll('button').some((b) => b.text() === 'Add condition')).toBe(true);

        const open = mountEditor('text');
        expect(open.text()).toContain('No validation rules.');
        expect(open.findAll('button').some((b) => b.text() === 'Add rule')).toBe(true);
    });
});
