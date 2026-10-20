/**
 * M180 (`R-f77aa45d`, `D156`) — the calculation editor's presets. A preset WRITES a formula, and a formula in a preset's
 * own shape READS BACK as that preset, so the editor reopens on Build it; anything else is a formula the author wrote,
 * and reopens on Formula.
 */

import { describe, expect, it } from 'vitest';

import { parsePreset, PRESETS, presetFormula, presetsFor, typeOfPreset, type Preset } from './calculation-presets';

const CANONICAL: Array<[string, Preset]> = [
    ['sum(${a}, ${b}, ${c})', { kind: 'sum', keys: ['a', 'b', 'c'] }],
    ['${a} - ${b}', { kind: 'difference', from: 'a', subtract: 'b' }],
    ["sum(${a}, ${b}) / (if(${a} = '', 0, 1) + if(${b} = '', 0, 1))", { kind: 'average', keys: ['a', 'b'] }],
    ["if(${a} = '', 0, 1) + if(${b} = '', 0, 1) + if(${c} = '', 0, 1)", { kind: 'count', keys: ['a', 'b', 'c'] }],
    ["if(${a} = '', 0, 1)", { kind: 'count', keys: ['a'] }],
    ['age(${dob})', { kind: 'age', key: 'dob' }],
    ["date_diff(${start}, ${end}, 'hours')", { kind: 'duration', start: { key: 'start' }, end: { key: 'end' }, unit: 'hours' }],
    ["date_diff(${admitted}, today(), 'days')", { kind: 'duration', start: { key: 'admitted' }, end: { today: true }, unit: 'days' }],
    ["date_add(${visit}, 30, 'days')", { kind: 'date_add', from: { key: 'visit' }, count: 30, unit: 'days' }],
    ["date_add(today(), -1, 'months')", { kind: 'date_add', from: { today: true }, count: -1, unit: 'months' }],
    ["if(${age} >= 18, 'adult', 'minor')", { kind: 'if', key: 'age', op: '>=', value: '18', then: 'adult', otherwise: 'minor' }],
    ["if(${status} = 'done', \"it's done\", 'open')", { kind: 'if', key: 'status', op: '=', value: 'done', then: "it's done", otherwise: 'open' }],
    ["concat(${first}, ' ', ${last})", { kind: 'join', keys: ['first', 'last'], separator: ' ' }],
    ['concat(${a}, ${b})', { kind: 'join', keys: ['a', 'b'], separator: '' }],
];

describe('calculation presets', () => {
    it.each(CANONICAL)('writes %s, and reads it back as the same preset', (formula, preset) => {
        expect(presetFormula(preset)).toBe(formula);
        expect(parsePreset(formula)).toEqual(preset);
    });

    it('reads any other formula as the author\'s own', () => {
        for (const formula of ['${a} * 2', 'sum(${a}, 2)', "if(${a} > 1, ${b}, 'x')", "concat(${a}, ' ', ${b}, '-', ${c})", 'today()', 'not a formula (', '']) {
            expect(parsePreset(formula)).toBeNull();
        }
    });

    it('offers each Type its own presets, and a preset sets the Type it gives', () => {
        expect(presetsFor('number').map((kind) => PRESETS[kind])).toEqual(['Sum', 'Difference', 'Average', 'Count answered', 'If … then …']);
        expect(presetsFor('text').map((kind) => PRESETS[kind])).toEqual(['Join answers', 'If … then …']);
        expect(presetsFor('date').map((kind) => PRESETS[kind])).toEqual(['A date N days from a date']);
        expect(presetsFor('duration').map((kind) => PRESETS[kind])).toEqual(['Age from a birth date', 'Duration between two dates or times']);

        expect(typeOfPreset({ kind: 'age', key: 'dob' })).toEqual({ type: 'duration', unit: 'years' });
        expect(typeOfPreset({ kind: 'duration', start: { key: 's' }, end: { key: 'e' }, unit: 'minutes' })).toEqual({ type: 'duration', unit: 'minutes' });
        expect(typeOfPreset({ kind: 'if', key: 'a', op: '>', value: '1', then: '10', otherwise: '0' })).toEqual({ type: 'number', unit: null });
        expect(typeOfPreset({ kind: 'if', key: 'a', op: '>', value: '1', then: 'yes', otherwise: 'no' })).toEqual({ type: 'text', unit: null });
        expect(typeOfPreset({ kind: 'join', keys: ['a'], separator: ' ' })).toEqual({ type: 'text', unit: null });
    });
});
