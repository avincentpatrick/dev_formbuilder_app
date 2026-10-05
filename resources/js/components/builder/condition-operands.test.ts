/**
 * M134 (`R-910d2286`) — the pure half of the condition editor's offer. The rendered behaviour is pinned in
 * `ConditionEditor.test.ts`; this pins the two rules a component test cannot see on its own: a catalogue without
 * kinds filters NOTHING (the pre-M134 editor, exactly), and a saved choice the editor would not offer stays.
 */

import { describe, expect, it } from 'vitest';

import { defaultOperator, firstSeedableField, objectFieldOptions, offeredOperators, offers, seedRow, subjectFieldOptions, UNAVAILABLE } from './condition-operands';
import type { ConditionCatalogue, OperandKindOption } from './types';

const LIST: OperandKindOption = { value: 'list', offered: true, orders: false, equals: false, includes: true, orders_with: [], literal_input: null };
const DATE: OperandKindOption = { value: 'date', offered: true, orders: true, equals: true, includes: false, orders_with: ['date', 'datetime'], literal_input: 'date' };
const NONE: OperandKindOption = { value: 'none', offered: false, orders: false, equals: false, includes: false, orders_with: [], literal_input: null };

const catalogue: ConditionCatalogue = {
    fields: [
        { key: 'remark', label: 'A note', numeric: false, options: [], operand_kind: 'none' },
        { key: 'hobbies', label: 'Hobbies', numeric: false, options: [], operand_kind: 'list' },
        { key: 'dob', label: 'Date of birth', numeric: false, options: [], operand_kind: 'date' },
        { key: 'mystery', label: 'Unclassified', numeric: false, options: [] },
    ],
    repeatables: [],
    kinds: [LIST, DATE, NONE],
};

describe('condition-operands', () => {
    it('filters nothing when there is no row to filter by', () => {
        for (const op of ['eq', 'gt', 'blank', 'includes']) expect(offers(null, op)).toBe(true);
        expect(subjectFieldOptions({ fields: catalogue.fields, repeatables: [] }, null)).toHaveLength(4);
        expect(objectFieldOptions(catalogue, null, 'gt', null)).toHaveLength(4);
    });

    it('offers a list membership and emptiness, never equality or ordering', () => {
        expect(['blank', 'not_blank', 'includes', 'excludes'].every((op) => offers(LIST, op))).toBe(true);
        expect(['eq', 'neq', 'gt', 'lte'].some((op) => offers(LIST, op))).toBe(false);
        expect(['eq', 'blank'].every((op) => offers(NONE, op))).toBe(false);
    });

    it('drops an operator a subject cannot take, but keeps the saved one visible and disabled', () => {
        const options = [{ value: 'eq', label: 'is' }, { value: 'gt', label: 'is more than' }, { value: 'includes', label: 'includes' }];

        expect(offeredOperators(options, LIST, 'includes')).toEqual([{ value: 'includes', label: 'includes' }]);
        expect(offeredOperators(options, LIST, 'eq')).toEqual([
            { value: 'eq', label: `is${UNAVAILABLE}`, disabled: true },
            { value: 'includes', label: 'includes' },
        ]);
    });

    it('offers a note as a subject only when it is the saved one, and then disabled', () => {
        expect(subjectFieldOptions(catalogue, null).map((o) => o.value)).toEqual(['field:hobbies', 'field:dob', 'field:mystery']);
        expect(subjectFieldOptions(catalogue, 'remark')[0]).toEqual({ value: 'field:remark', label: `A note${UNAVAILABLE}`, disabled: true });
    });

    it('lets a date be ordered only against its own kinds, keeping an unclassified question', () => {
        expect(objectFieldOptions(catalogue, DATE, 'gt', null).map((o) => o.value)).toEqual(['field:dob', 'field:mystery']);
    });

    it('seeds a new row on the first question that takes a value, still incomplete', () => {
        expect(firstSeedableField(catalogue)?.key).toBe('hobbies');
        expect(seedRow(catalogue, 'hobbies')).toEqual({ kind: 'selected', field: 'hobbies', value: '', negated: false });
        expect(seedRow(catalogue, 'dob')).toEqual({ kind: 'compare', op: 'eq', left: { kind: 'field', key: 'dob' }, right: { kind: 'text', value: '' } });
        expect(defaultOperator(LIST)).toBe('includes');
        expect(defaultOperator(null)).toBe('eq');
    });
});
