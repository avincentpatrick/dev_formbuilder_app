import { describe, expect, it } from 'vitest';
import type { BuilderValidation, RuleTypeOption } from './types';
import { applyCombinator, describeGrouping, familyOf, joinFamily } from './rule-grouping';

/**
 * M131 (`R-799d60f5`, `D80`) — one all/any switch per family, mapped onto the engines' flat groups: what the switch
 * can show, what it writes, and that it never touches another family or a grouping it cannot show.
 */

function rule(value: string, family: 'required' | 'skip' | 'constraint'): RuleTypeOption {
    return {
        value,
        label: value,
        shapes: ['text', 'number'],
        takes_operator: family !== 'constraint',
        takes_related_field: family !== 'constraint',
        operator_may_be_empty: false,
        governs_requiredness: family === 'required',
        governs_relevance: family === 'skip',
        related_comparison: null,
    };
}

const RULES = [rule('required_if', 'required'), rule('skip_if', 'skip'), rule('min_length', 'constraint'), rule('max_length', 'constraint')];

function row(rule_type: string | null, sequence: number, extra: Partial<BuilderValidation> = {}): BuilderValidation {
    return {
        rule_type,
        operator: null,
        rule_value: null,
        expression: null,
        error_message: null,
        related_field_key: null,
        logic_group: null,
        logic_operator: null,
        sequence,
        ...extra,
    };
}

describe('the family a rule is folded in', () => {
    it('reads the transmitted facts, and a raw expression is a constraint', () => {
        expect(familyOf(row('required_if', 0), RULES)).toBe('required');
        expect(familyOf(row('skip_if', 0), RULES)).toBe('skip');
        expect(familyOf(row('min_length', 0), RULES)).toBe('constraint');
        expect(familyOf(row(null, 0, { expression: '. > 1' }), RULES)).toBe('constraint');
    });
});

describe('what the switch can show', () => {
    it('reads no group as the family default: ANY for required, ALL for constraints', () => {
        const rows = [row('required_if', 0), row('required_if', 1), row('min_length', 2), row('max_length', 3)];

        expect(describeGrouping(rows, 'required', RULES)).toEqual({ kind: 'plain', combinator: 'any' });
        expect(describeGrouping(rows, 'constraint', RULES)).toEqual({ kind: 'plain', combinator: 'all' });
    });

    it('reads one group of the whole family, joined one way, as that combinator', () => {
        const rows = [
            row('required_if', 0, { logic_group: 'g', logic_operator: 'and' }),
            row('required_if', 1, { logic_group: 'g', logic_operator: 'and' }),
        ];

        expect(describeGrouping(rows, 'required', RULES)).toEqual({ kind: 'grouped', combinator: 'all', token: 'g' });
    });

    it('calls every other shape custom, with the reason in words', () => {
        const reason = (rows: BuilderValidation[]): string => {
            const g = describeGrouping(rows, 'constraint', RULES);
            return g.kind === 'custom' ? g.reason : 'not custom';
        };

        expect(reason([row('min_length', 0, { logic_group: 'a' }), row('max_length', 1, { logic_group: 'b', logic_operator: 'or' })])).toContain('more than one group');
        expect(reason([row('min_length', 0, { logic_group: 'a' }), row('max_length', 1)])).toContain('only some');
        expect(reason([row('min_length', 0, { logic_group: 'a' }), row('max_length', 1, { logic_group: 'a', logic_operator: 'or' }), row('required_if', 2, { logic_group: 'a', logic_operator: 'or' })])).toContain('another kind');
        expect(reason([row('min_length', 0, { logic_group: 'a' }), row('max_length', 1, { logic_group: 'a' })])).toContain('does not say how it joins');
        expect(
            reason([
                row('min_length', 0, { logic_group: 'a' }),
                row('max_length', 1, { logic_group: 'a', logic_operator: 'or' }),
                row(null, 2, { expression: '. != 1', logic_group: 'a', logic_operator: 'and' }),
            ]),
        ).toContain('mix AND and OR');
    });

    it('reads a one-rule group as a rule on its own', () => {
        expect(describeGrouping([row('min_length', 0, { logic_group: 'a' })], 'constraint', RULES)).toEqual({ kind: 'plain', combinator: 'all' });
    });
});

describe('what the switch writes', () => {
    it('groups the family under its new-family token with the connective on every member, and touches no other row', () => {
        const required = row('required_if', 0);
        const rows = [required, row('min_length', 1), row('max_length', 2)];

        const next = applyCombinator(rows, 'constraint', 'any', RULES);

        expect(next[0]).toBe(required);
        expect(next.slice(1).map((r) => [r.logic_group, r.logic_operator])).toEqual([
            ['new-constraint', 'or'],
            ['new-constraint', 'or'],
        ]);
        expect(describeGrouping(next, 'constraint', RULES)).toEqual({ kind: 'grouped', combinator: 'any', token: 'new-constraint' });
    });

    it('keeps the family’s own group uuid when it switches combinator', () => {
        const rows = [row('required_if', 0, { logic_group: 'uuid-1', logic_operator: 'and' }), row('required_if', 1, { logic_group: 'uuid-1', logic_operator: 'and' })];

        const next = applyCombinator(rows, 'required', 'all', RULES);

        expect(next.map((r) => r.logic_group)).toEqual(['uuid-1', 'uuid-1']);
    });

    it('ungroups the family on its default, writing null on both columns', () => {
        const rows = [row('min_length', 0, { logic_group: 'g', logic_operator: 'or' }), row('max_length', 1, { logic_group: 'g', logic_operator: 'or' })];

        expect(applyCombinator(rows, 'constraint', 'all', RULES).map((r) => [r.logic_group, r.logic_operator])).toEqual([
            [null, null],
            [null, null],
        ]);
    });

    it('never reuses a token another family also holds', () => {
        const rows = [
            row('required_if', 0, { logic_group: 'shared', logic_operator: 'and' }),
            row('min_length', 1, { logic_group: 'shared' }),
            row('max_length', 2),
        ];

        const next = applyCombinator(rows, 'constraint', 'any', RULES);

        expect(next[1].logic_group).toBe('new-constraint');
        expect(next[0].logic_group).toBe('shared');
    });
});

describe('a rule joining its family', () => {
    it('joins a grouped family, and stands alone in a plain or custom one', () => {
        const grouped = [row('min_length', 0, { logic_group: 'g', logic_operator: 'or' }), row('max_length', 1, { logic_group: 'g', logic_operator: 'or' })];

        expect(joinFamily(grouped, 'constraint', RULES)).toEqual({ logic_group: 'g', logic_operator: 'or' });
        expect(joinFamily([row('min_length', 0), row('max_length', 1)], 'constraint', RULES)).toEqual({ logic_group: null, logic_operator: null });
        expect(joinFamily([row('min_length', 0, { logic_group: 'a' }), row('max_length', 1)], 'constraint', RULES)).toEqual({ logic_group: null, logic_operator: null });
    });
});
