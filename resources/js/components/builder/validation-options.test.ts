import { describe, expect, it } from 'vitest';
import type { BuilderValidation, ComparableField, OperatorOption, RuleTypeOption } from './types';
import { mayCompare, relatedShapes, repointPatch, ruleChangePatch } from './validation-options';

/**
 * M131 (`R-57711a3a`) — the validation editor offers only the compared questions publish accepts, from the
 * transmitted facts: the union of operator shapes for a rule that reads an operator, and its `related_comparison`
 * operator's shapes for a field comparison.
 */

// The operator table as `BuilderPresenter::enums()` transmits it (shapes per `ValueShape::allowsOperator()`).
const ALL = ['text', 'number', 'duration', 'scale', 'date', 'yes_no', 'choice', 'multi_choice'];
const OPERATORS: OperatorOption[] = [
    { value: 'gt', label: 'is more than', shapes: ['number', 'duration', 'scale'] },
    { value: 'lt', label: 'is less than', shapes: ['number', 'duration', 'scale'] },
    { value: 'eq', label: 'is', shapes: ALL },
    { value: 'is_null', label: 'is empty', shapes: ALL },
    { value: 'contains', label: 'contains', shapes: ['text'] },
];

function rule(value: string, facts: Partial<RuleTypeOption>): RuleTypeOption {
    return {
        value,
        label: value,
        shapes: ALL,
        takes_operator: false,
        takes_related_field: false,
        operator_may_be_empty: false,
        governs_requiredness: false,
        related_comparison: null,
        ...facts,
    } as RuleTypeOption;
}

const REQUIRED_IF = rule('required_if', { takes_operator: true, takes_related_field: true, governs_requiredness: true });
const REQUIRED_WITH = rule('required_with', {
    takes_operator: true,
    takes_related_field: true,
    operator_may_be_empty: true,
    governs_requiredness: true,
    related_comparison: 'is_null',
});
const GREATER = rule('greater_than_field', { takes_related_field: true, related_comparison: 'gt' });
const MIN_LENGTH = rule('min_length', {});

const FIELDS: ComparableField[] = [
    { key: 'age', label: 'Age', value_shape: 'number' },
    { key: 'pain', label: 'Pain (1-5)', value_shape: 'scale' },
    { key: 'name', label: 'Name', value_shape: 'text' },
    { key: 'dob', label: 'Date of birth', value_shape: 'date' },
    { key: 'intro', label: 'Intro note', value_shape: 'no_answer' },
];

const field = (key: string) => FIELDS.find((f) => f.key === key)!;

function row(patch: Partial<BuilderValidation>): BuilderValidation {
    return {
        rule_type: 'required_if',
        operator: null,
        rule_value: null,
        expression: null,
        error_message: null,
        related_field_key: null,
        sequence: 0,
        ...patch,
    } as BuilderValidation;
}

describe('which questions a rule may compare', () => {
    it('lets a field comparison name only numbers, durations and scales — a likert scale included', () => {
        expect([...relatedShapes(GREATER, OPERATORS)].sort()).toEqual(['duration', 'number', 'scale']);
        expect(mayCompare(GREATER, OPERATORS, field('pain'))).toBe(true);
        for (const key of ['name', 'dob', 'intro']) {
            expect(mayCompare(GREATER, OPERATORS, field(key)), key).toBe(false);
        }
    });

    it('lets an _if rule name anything some operator can read, which is never a note', () => {
        expect(mayCompare(REQUIRED_IF, OPERATORS, field('name'))).toBe(true);
        expect(mayCompare(REQUIRED_IF, OPERATORS, field('dob'))).toBe(true);
        expect(mayCompare(REQUIRED_IF, OPERATORS, field('intro'))).toBe(false);
        expect(mayCompare(REQUIRED_WITH, OPERATORS, field('intro'))).toBe(false);
    });

    it('lets a rule that names no question name none', () => {
        expect(relatedShapes(MIN_LENGTH, OPERATORS).size).toBe(0);
    });
});

describe('re-pointing a rule at another question', () => {
    it('clears an operator the new question cannot take, and the value it compared against', () => {
        const patch = repointPatch(row({ related_field_key: 'age', operator: 'gt', rule_value: '18' }), 'name', FIELDS, OPERATORS);

        expect(patch).toEqual({ related_field_key: 'name', operator: null, rule_value: null });
    });

    it('keeps an operator the new question can take', () => {
        const patch = repointPatch(row({ related_field_key: 'age', operator: 'gt', rule_value: '18' }), 'pain', FIELDS, OPERATORS);

        expect(patch).toEqual({ related_field_key: 'pain' });
    });
});

describe('changing a rule\'s kind', () => {
    it('drops a compared question the new kind cannot name — required_if on text to greater_than_field', () => {
        const patch = ruleChangePatch(row({ related_field_key: 'name', operator: 'eq', rule_value: 'x' }), GREATER, FIELDS, OPERATORS);

        expect(patch).toEqual({ rule_type: 'greater_than_field', related_field_key: null, operator: null });
    });

    it('keeps a compared question and operator both kinds can use', () => {
        const patch = ruleChangePatch(row({ related_field_key: 'age', operator: 'gt' }), REQUIRED_WITH, FIELDS, OPERATORS);

        expect(patch).toEqual({ rule_type: 'required_with', related_field_key: 'age', operator: 'gt' });
    });

    it('clears both for a kind that names no question', () => {
        const patch = ruleChangePatch(row({ related_field_key: 'age', operator: 'gt' }), MIN_LENGTH, FIELDS, OPERATORS);

        expect(patch).toEqual({ rule_type: 'min_length', related_field_key: null, operator: null });
    });
});
