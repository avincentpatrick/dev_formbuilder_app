/**
 * Public-API smoke test — exercises the `index.ts` barrel that Increment F6b's SPA will import (the golden
 * runners import submodules directly, so this is the one test that guards the published entry surface).
 * The exhaustive behavioural coverage lives in the golden-vector suites; this only asserts the barrel wires
 * up and the two factory functions produce working engines.
 */

import { describe, expect, it } from 'vitest';

import {
    ABSENT,
    EvaluationContext,
    ExpressionSyntaxError,
    GRAMMAR_VERSION,
    makeExpressionEvaluator,
    makeSemanticValidator,
    type SemanticInput,
} from '../index';

describe('form-runtime engine public API', () => {
    it('pins the grammar version the drift contract is keyed on', () => {
        expect(GRAMMAR_VERSION).toBe('2.0');
    });

    it('evaluates relevance/skip logic through the expression evaluator', () => {
        const engine = makeExpressionEvaluator();
        const context = new EvaluationContext({ age: 20, langs: ['en', 'fr'] });

        expect(engine.evaluateBoolean('${age} > 18 and selected(${langs}, "fr")', context)).toBe(true);
        expect(engine.evaluate('${missing}', context)).toBeNull(); // absent → normalised to null at the boundary
    });

    it('evaluates grammar-v2.0 arithmetic + the function library', () => {
        const engine = makeExpressionEvaluator();
        const context = new EvaluationContext({ a: 5, b: 3, roster: [{ n: 1 }, { n: 2 }] }, undefined, '2026-07-11T09:30:00+00:00');

        expect(engine.evaluate('${a} + ${b} * 2', context)).toBe(11);
        expect(engine.evaluateBoolean('${a} >= 5', context)).toBe(true);
        expect(engine.evaluate('if(${a} > ${b}, ${a}, ${b})', context)).toBe(5);
        expect(engine.evaluate('count(${roster})', context)).toBe(2);
        expect(engine.evaluate('today()', context)).toBe('2026-07-11');
    });

    it('surfaces a stable slug for an authoring error', () => {
        const engine = makeExpressionEvaluator();

        expect(() => engine.evaluateBoolean('sum(1, 2)', new EvaluationContext({}))).toThrowError(ExpressionSyntaxError);
    });

    it('runs the semantic validator: relevance mask, effective answers, and a required error', () => {
        const input: SemanticInput = {
            fields: [
                { id: 'trigger', key: 'trigger', sequence: 0, is_required: 'optional', form_section_id: null, relevant_expression: null },
                { id: 'detail', key: 'detail', sequence: 1, is_required: 'required', form_section_id: null, relevant_expression: "${trigger} = 'yes'" },
            ],
            sections: [],
            validations: [],
            answers: { trigger: 'yes' },
            locale: 'en',
        };

        const result = makeSemanticValidator().evaluate(input);

        expect(result.fieldRelevance).toEqual({ trigger: true, detail: true });
        expect(result.effectiveAnswers).toEqual({ trigger: 'yes' });
        expect(result.passed()).toBe(false);
        expect(result.errorsFor('detail').map((error) => error.rule)).toEqual(['field_required']);
    });

    it('exposes the absent sentinel as a shared symbol', () => {
        expect(typeof ABSENT).toBe('symbol');
    });
});

/**
 * Increment H21a — the TypeScript twins of the four settle-loop defects H1f recorded (Doc #27 §3.2, §3.3,
 * §4.3). Each asserts the SAME expectations as its Pest counterpart in
 * `tests/Unit/Validation/SemanticValidatorTest.php`.
 *
 * These are hand-written rather than vectored on purpose. All four live inside `evaluate()`, which IS the
 * golden runners' entry point, and no CURRENT vector discriminates any of them — a far weaker guarantee than
 * "the runners cannot see them". Doc #27 §9 adds no golden vectors, so this file is where the TypeScript side
 * of the guarantee actually lives.
 */
describe('H21a settle-loop behaviour (the PHP twin of tests/Unit/Validation/SemanticValidatorTest.php)', () => {
    const member = (key: string, sectionId: string, sequence: number, relevant: string | null = null) => ({
        id: key, key, sequence, is_required: 'optional' as const, form_section_id: sectionId, relevant_expression: relevant,
    });
    const flat = (key: string, sequence: number, relevant: string | null = null, fieldType?: string) => ({
        id: key, key, sequence, is_required: 'optional' as const, form_section_id: null, relevant_expression: relevant,
        ...(fieldType === undefined ? {} : { field_type: fieldType }),
    });

    it('resolves count() over a repeatable section inside a relevant_expression', () => {
        // Doc #27 §3.3 — the repeat array was intersected away before any relevance expression saw it, so
        // count(${hh}) read ABSENT and returned 0 forever.
        const base = {
            fields: [member('member_name', 's_hh', 1), member('note_text', 's_sum', 2)],
            sections: [
                { id: 's_hh', key: 'hh', relevant_expression: null, is_repeatable: true },
                { id: 's_sum', key: 'summary', relevant_expression: 'count(${hh}) > 0' },
            ],
            validations: [],
            locale: 'en',
        };
        const validator = makeSemanticValidator();

        expect(validator.evaluate({ ...base, answers: { hh: [{ member_name: 'Ana' }] } } as SemanticInput)
            .sectionRelevance.summary).toBe(true);
        expect(validator.evaluate({ ...base, answers: { hh: [] } } as SemanticInput)
            .sectionRelevance.summary).toBe(false);
    });

    it('resolves count() over the group inside a repeat MEMBER relevant_expression', () => {
        const base = {
            fields: [member('member_name', 's_hh', 1), member('guardian', 's_hh', 2, 'count(${hh}) > 1')],
            sections: [{ id: 's_hh', key: 'hh', relevant_expression: null, is_repeatable: true }],
            validations: [],
            locale: 'en',
        };
        const validator = makeSemanticValidator();

        const two = validator.evaluate({ ...base, answers: { hh: [
            { member_name: 'Ana', guardian: 'Y' },
            { member_name: 'Ben', guardian: 'Z' },
        ] } } as SemanticInput);
        const one = validator.evaluate({ ...base, answers: { hh: [{ member_name: 'Ana', guardian: 'Y' }] } } as SemanticInput);

        expect(two.repeatFieldRelevance.hh[0].guardian).toBe(true);
        expect(one.repeatFieldRelevance.hh[0].guardian).toBe(false);
        expect(one.effectiveAnswers.hh).toEqual([{ member_name: 'Ana' }]);
    });

    it('does not seed a section key that collides with a field key', () => {
        // Doc #27 amendment A7 — the two tables carry independent unique indexes, so a collision is
        // reachable, and seeding blindly would re-admit the answer of a field relevance had just pruned.
        const result = makeSemanticValidator().evaluate({
            fields: [
                flat('gate', 1),
                flat('roster', 2, "${gate} = 'yes'"),
                member('member_name', 's_hh', 3),
                member('later_field', 's_x', 4),
            ],
            sections: [
                { id: 's_hh', key: 'roster', relevant_expression: null, is_repeatable: true },
                { id: 's_x', key: 'later', relevant_expression: "${roster} = 'secret'" },
            ],
            validations: [],
            answers: { gate: 'no', roster: 'secret' },
            locale: 'en',
        } as SemanticInput);

        expect(result.fieldRelevance.roster).toBe(false);
        expect(result.effectiveAnswers).not.toHaveProperty('roster');
        expect(result.sectionRelevance.later).toBe(false);
    });

    it('returns a section mask and a field mask that agree even when the settle exhausts its bound', () => {
        // Doc #27 §3.2 (amendment A3) — an oscillating pair never reaches the fixed point, so before H21a the
        // two masks were returned one iteration apart and the step model is the first consumer to read both.
        //
        // THE FOURTH FIELD IS NOT PADDING: the bound is `fields + sections + 2`, so the field count decides
        // which phase of the oscillation the loop stops on, and only one phase exposes the artifact. Verified
        // empirically — at 3 and 5 fields the tightening is genuinely a no-op and a fixture built without this
        // field would pass either way.
        const result = makeSemanticValidator().evaluate({
            fields: [flat('a', 1, "${b} = ''"), flat('b', 2, "${a} = ''"), member('inside', 's1', 3), flat('keep', 4)],
            sections: [{ id: 's1', key: 'sec', relevant_expression: "${a} = 'x'" }],
            validations: [],
            answers: { a: 'x', b: 'y', inside: 'kept', keep: 'k' },
            locale: 'en',
        } as SemanticInput);

        // Asserted directly, not as a two-branch invariant: a branching expectation is satisfied by BOTH the
        // fixed and the broken behaviour.
        expect(result.sectionRelevance.sec).toBe(false);
        expect(result.fieldRelevance.inside).toBe(false);
        expect(result.effectiveAnswers).not.toHaveProperty('inside');
    });

    it('stops enforcing min_instances on a repeatable section the respondent never sees', () => {
        // Doc #27 §4.3 (amendment A4). This is the half a PHP-only fix would have left divergent: the bounds
        // check has a byte-for-byte twin here, and no repeat vector in the corpus uses a non-rendering member.
        const sections = [{ id: 's_hh', key: 'hh', relevant_expression: null, is_repeatable: true, min_instances: 2 }];
        const validator = makeSemanticValidator();

        const hidden = validator.evaluate({
            fields: [{ ...member('token', 's_hh', 1), field_type: 'hidden' }],
            sections, validations: [], answers: {}, locale: 'en',
        } as SemanticInput);
        expect(hidden.errors).toEqual([]);

        // Zero instances is vacuously visible, so a group that DOES render still blocks — without this arm
        // the narrowing would silently disable the rule instead of scoping it.
        const visible = validator.evaluate({
            fields: [member('member_name', 's_hh', 1)],
            sections, validations: [], answers: {}, locale: 'en',
        } as SemanticInput);
        expect(visible.errorsFor('hh').map((e) => e.rule)).toEqual(['min_instances']);
    });

    it('keeps enforcing max_instances ahead of the per-instance settle', () => {
        const result = makeSemanticValidator().evaluate({
            fields: [member('member_name', 's_hh', 1)],
            sections: [{ id: 's_hh', key: 'hh', relevant_expression: null, is_repeatable: true, max_instances: 1 }],
            validations: [],
            answers: { hh: [{ member_name: 'Ana' }, { member_name: 'Ben' }] },
            locale: 'en',
        } as SemanticInput);

        expect(result.errorsFor('hh').map((e) => e.rule)).toEqual(['max_instances']);
    });
});
