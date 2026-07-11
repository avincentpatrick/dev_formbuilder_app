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
