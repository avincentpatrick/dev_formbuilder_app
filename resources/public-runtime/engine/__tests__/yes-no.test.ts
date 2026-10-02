/**
 * Increment M124 (R-9f296f7e) — the TypeScript twins of `Coercion::yesNoLiteral()` / `yesNoAnswer()`, pinned value
 * by value against the same tables `tests/Unit/Expressions/YesNoCoercionTest.php` pins in PHP, plus the one
 * property only this engine can break: Stage 3 canonicalizes a COPY, because the runtime hands it the live answer
 * map by reference (`useFormRuntime.ts` `snapshotAnswers()` copies flat values by reference).
 *
 * ⛔ The trim set is PHP's, not JavaScript's. `.trim()` strips NBSP and keeps NUL — the reverse of PHP on both —
 * so the NUL and NBSP rows are the ones that turn red if the twin ever borrows `.trim()`. Control characters are
 * built with `String.fromCharCode()`, never written as an escape.
 */
import { describe, expect, it } from 'vitest';
import { ABSENT, toBool, yesNoAnswer, yesNoLiteral, type MaybeAbsent } from '../coercion';
import type { SchemaField, SchemaSection, SemanticInput } from '../schema';
import { makeSemanticValidator } from '../semantic-validator';

const TAB = String.fromCharCode(9);
const NUL = String.fromCharCode(0);
const VERTICAL_TAB = String.fromCharCode(11);
const NBSP = String.fromCharCode(0xa0);

describe('yesNoLiteral — the strict reading of whatever a yes/no answer is compared with', () => {
    const cases: [string, MaybeAbsent, boolean | null][] = [
        ['yes', 'yes', true],
        ['Yes', 'Yes', true],
        ['padded YES', ' YES ', true],
        ['true', 'true', true],
        ['TRUE', 'TRUE', true],
        ['string 1', '1', true],
        ['number 1', 1, true],
        ['no', 'no', false],
        ['No', 'No', false],
        ['false', 'false', false],
        ['string 0', '0', false],
        ['number 0', 0, false],
        ['negative zero', -0, false],
        ['maybe', 'maybe', null],
        ['y', 'y', null],
        ['n', 'n', null],
        ['empty string', '', null],
        ['a space', ' ', null],
        ['string 1.0', '1.0', null],
        ['string 01', '01', null],
        ['number 2', 2, null],
        ['NaN', NaN, null],
        ['a boolean true', true, null],
        ['a boolean false', false, null],
        ['null', null, null],
        ['absent', ABSENT, null],
        ['a list', ['yes'], null],
        ['a tab is trimmed', `${TAB}yes`, true],
        ['NUL is trimmed', `${NUL}no${NUL}`, false],
        ['a vertical tab is trimmed', `${VERTICAL_TAB}yes`, true],
        ['NBSP is not trimmed', `${NBSP}yes`, null],
    ];

    it.each(cases)('%s', (_name, value, expected) => {
        expect(yesNoLiteral(value)).toBe(expected);
    });
});

describe('yesNoAnswer — a yes/no answer by the table Stage 2 has always applied', () => {
    const cases: [string, MaybeAbsent, boolean][] = [
        ['yes', 'yes', true],
        ['no', 'no', false],
        ['padded NO', ' NO ', false],
        ['false', 'false', false],
        ['string 0', '0', false],
        ['n reads as yes', 'n', true],
        ['off reads as yes', 'off', true],
        ['maybe reads as yes', 'maybe', true],
        ['NUL-led no is trimmed', `${NUL}no`, false],
        ['NBSP-led no is not', `${NBSP}no`, true],
        ['a boolean true', true, true],
        ['a boolean false', false, false],
        ['number 1', 1, true],
        ['number 0', 0, false],
        ['null', null, false],
    ];

    it.each(cases)('%s', (_name, value, expected) => {
        expect(yesNoAnswer(value)).toBe(expected);
    });

    it('is not a superset of toBool, in either direction', () => {
        expect(toBool('0.0')).toBe(false);
        expect(yesNoAnswer('0.0')).toBe(true);
        expect(toBool(' 0 ')).toBe(true);
        expect(yesNoAnswer(' 0 ')).toBe(false);
    });
});

describe('Stage 3 reads a yes/no answer as the server does, without touching the map it was handed', () => {
    function yesNoField(key: string, sectionId: string | null = null): SchemaField {
        return {
            id: key,
            key,
            sequence: 0,
            field_type: 'yes_no',
            is_required: 'optional',
            form_section_id: sectionId,
            relevant_expression: null,
        };
    }

    function input(fields: SchemaField[], sections: SchemaSection[], answers: SemanticInput['answers']): SemanticInput {
        return { fields, sections, validations: [], answers, locale: 'en', now: null };
    }

    it('canonicalizes a flat answer in a copy', () => {
        const answers = { consent: 'yes' };
        const handed = input([yesNoField('consent')], [], answers);

        const result = makeSemanticValidator().evaluate(handed);

        expect(result.effectiveAnswers).toEqual({ consent: true });
        expect(handed.answers).toBe(answers);
        expect(answers).toEqual({ consent: 'yes' });
    });

    it('canonicalizes a repeat member in a copy of its instance', () => {
        const instance = { smoker: 'no' };
        const handed = input(
            [yesNoField('smoker', 'hh')],
            [{ id: 'hh', key: 'hh', sequence: 0, relevant_expression: null, is_repeatable: true }],
            { hh: [instance] },
        );

        const result = makeSemanticValidator().evaluate(handed);

        expect(result.effectiveAnswers).toEqual({ hh: [{ smoker: false }] });
        expect(instance).toEqual({ smoker: 'no' });
    });

    it('hands back the very input when the version has no yes/no question', () => {
        const answers = { note: 'no' };
        const handed = input(
            [{ id: 'note', key: 'note', sequence: 0, field_type: 'short_text', is_required: 'optional', form_section_id: null, relevant_expression: null }],
            [],
            answers,
        );

        expect(makeSemanticValidator().evaluate(handed).effectiveAnswers).toEqual({ note: 'no' });
    });
});
