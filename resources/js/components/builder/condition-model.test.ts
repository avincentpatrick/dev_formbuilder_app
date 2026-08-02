/**
 * The serializer round-trip Doc #27 §9 owes H21d2 — "a round-trip test over every condition shape the editor
 * can author" — plus the classifier's refusals and the three literals grammar v2.0 cannot express.
 *
 * `tests/fixtures/condition-serializer.json` is driven from BOTH directions here, and a THIRD time by
 * `tests/Unit/Expressions/ConditionSerializerParityTest.php`, which parses every `text` with PHP's
 * `ExpressionParser`. That third driver is the point: this module is the third place in the repository where
 * expression syntax is CONSTRUCTED, against two parsers held byte-identical by the golden corpus, and text
 * that only the TypeScript parser accepts would otherwise be a draft that saves cleanly and then refuses to
 * publish. The fixture sits outside `tests/golden/`, carries no `grammar_version` key and no manifest, so it
 * moves neither the 296-site count nor the 114-vector total (the `tests/fixtures/step-projection.json`
 * precedent, H21a amendment A6).
 *
 * `process.cwd()` rather than `import.meta.url` arithmetic — Vitest's cwd is its config's directory, and the
 * G11 CI failure PROGRESS records was a directory-walk losing the `file:` scheme under happy-dom's `URL`.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe as group, expect, it } from 'vitest';

import { describe } from './condition-describer';
import {
    normalize,
    parseExpression,
    sameCondition,
    serialize,
    toCondition,
    type Condition,
} from './condition-model';

type FixtureCase = { name: string; note?: string; condition: Condition; text: string };

const cases: FixtureCase[] = JSON.parse(
    readFileSync(join(process.cwd(), 'tests', 'fixtures', 'condition-serializer.json'), 'utf-8'),
);

function conditionOf(expression: string): Condition {
    const parsed = toCondition(parseExpression(expression));
    if (parsed === null) throw new Error(`expected «${expression}» to be representable`);

    return parsed;
}

function textOf(condition: Condition): string {
    const result = serialize(condition);
    if (result.text === undefined) throw new Error(`expected a printable condition, refused: ${JSON.stringify(result.error)}`);

    return result.text;
}

group('the round trip, over every shape the editor can author', () => {
    it.each(cases.map((c) => [c.name, c] as const))('prints %s as its canonical text', (_name, testCase) => {
        expect(textOf(testCase.condition)).toBe(testCase.text);
    });

    it.each(cases.map((c) => [c.name, c] as const))('reads %s back to the same condition', (_name, testCase) => {
        expect(conditionOf(testCase.text)).toEqual(testCase.condition);
    });

    it.each(cases.map((c) => [c.name, c] as const))('is stable on a second pass for %s', (_name, testCase) => {
        // Printing what was just read must not drift. A canonicalizer that is not idempotent would rewrite
        // an author's condition every time they touched the panel, which is the failure §8 calls silent.
        expect(textOf(conditionOf(testCase.text))).toBe(testCase.text);
    });

    it('reads a non-trivial fixture with unique case names, covering every kind', () => {
        // Anti-vacuity, the `PdfFieldRoleTest` shape: a fixture that failed to load, or an `it.each` that
        // silently collapsed to zero rows, would make every assertion above pass by never running.
        expect(cases.length).toBeGreaterThanOrEqual(24);
        expect(new Set(cases.map((c) => c.name)).size).toBe(cases.length);

        const kinds = new Set(cases.map((c) => c.condition.kind));
        expect([...kinds].sort()).toEqual(['blank', 'compare', 'count', 'group', 'selected']);

        // …and the two shapes that would pass a flat, unnegated fixture while being broken: a group nested
        // inside a group, and a negation.
        const nested = (c: Condition): boolean =>
            c.kind === 'group' && c.children.some((child) => child.kind === 'group' || nested(child));
        expect(cases.some((c) => nested(c.condition))).toBe(true);
        expect(cases.some((c) => c.condition.kind === 'selected' && c.condition.negated)).toBe(true);
    });
});

group('the classifier is the describer’s, and refuses the same shapes', () => {
    it.each([
        ['arithmetic in an operand', '${age} + 1 > 18'],
        ['a function that returns a value, not a condition', 'if(${age} > 18, 1, 0) = 1'],
        ['int()', 'int(${age}) > 18'],
        ['a clock function', "${tier} = today()"],
        ['not() over a whole chain', "not(${age} > 18 and ${tier} = 'gold')"],
        ['count() on the right-hand side', '0 < count(${roster})'],
        ['count() compared to a field rather than a number', '${age} > count(${roster})'],
        ['an emptiness test with an ordering operator', "${notes} >= ''"],
        ['a describable clause with ONE undescribable operand', "${tier} = 'gold' and ${age} + 1 > 18"],
    ])('%s is not representable', (_label, expression) => {
        expect(toCondition(parseExpression(expression))).toBeNull();
    });

    it('agrees with the describer on every fixture case and every refusal above', () => {
        // The agreement is definitional now — `describe()` calls `toCondition()` — and this asserts the
        // wiring rather than a treaty between two implementations. It reddens if someone re-fuses a second
        // classifier into the describer, which is the regression Doc #27 amendment D2 names by name.
        for (const testCase of cases) {
            expect(describe(testCase.text).status).toBe('described');
        }
        expect(describe('${age} + 1 > 18').status).toBe('opaque');
        expect(describe('${age} = = 1').status).toBe('invalid');
        expect(describe(null).status).toBe('blank');
    });
});

group('what grammar v2.0 cannot express, the printer refuses rather than mangles', () => {
    const field = (key: string) => ({ kind: 'field', key }) as const;

    it('refuses a value containing BOTH quote characters', () => {
        // The lexer has no escape sequences at all: a string runs to the first matching delimiter. There is
        // no third delimiter to reach for, so this value is not expressible — and emitting something that
        // lexes into a different string would be the silent rewrite §8 calls the disqualifier.
        const result = serialize({ kind: 'compare', op: 'eq', left: field('note'), right: { kind: 'text', value: 'it\'s "fine"' } });

        expect(result.error).toEqual({ reason: 'quotes', value: 'it\'s "fine"' });
        expect(result.text).toBeUndefined();
    });

    it('accepts either quote character on its own, which is the anti-vacuity twin', () => {
        expect(textOf({ kind: 'compare', op: 'eq', left: field('note'), right: { kind: 'text', value: "it's" } })).toBe(
            '${note} = "it\'s"',
        );
        expect(textOf({ kind: 'compare', op: 'eq', left: field('note'), right: { kind: 'text', value: '"fine"' } })).toBe(
            '${note} = \'"fine"\'',
        );
    });

    it.each([
        ['a magnitude that needs exponent notation', 1e21],
        ['a fraction that needs exponent notation', 1e-7],
        ['infinity', Number.POSITIVE_INFINITY],
        ['not a number', Number.NaN],
    ])('refuses %s', (_label, value) => {
        // `String(1e21)` is "1e+21" and `String(1e-7)` is "1e-7"; `lexNumber` reads digits and at most one
        // fractional part, so both would lex as something else entirely.
        expect(serialize({ kind: 'compare', op: 'eq', left: field('n'), right: { kind: 'number', value } }).error).toEqual({
            reason: 'number',
            value,
        });
    });

    it('refuses a key that would lex as a malformed reference', () => {
        expect(serialize({ kind: 'compare', op: 'eq', left: field('a-b'), right: { kind: 'number', value: 1 } }).error).toEqual({
            reason: 'key',
            key: 'a-b',
        });
        expect(serialize({ kind: 'count', op: 'gt', section: '1x', n: 0 }).error).toEqual({ reason: 'key', key: '1x' });
    });

    it('refuses to print a condition whose text would parse back to a DIFFERENT condition', () => {
        // Found by mutation: deleting the printer's deep-equality check reddened nothing, because the
        // length case below is caught by the re-parse THROWING rather than by the comparison. This is the
        // case that reaches the comparison. `compare eq <field> <empty text>` prints as `${a} = ''`, which
        // the parser reads back as the EMPTINESS idiom — a different condition with a different reading.
        //
        // The editor never builds it (`isComplete()` refuses an empty operand first), but `serialize()` is
        // a reachable function on its own, and handing back text that means something other than what it
        // was given is precisely the silent rewrite §8 calls the disqualifier.
        const result = serialize({ kind: 'compare', op: 'eq', left: field('a'), right: { kind: 'text', value: '' } });

        expect(result.error).toEqual({ reason: 'unparseable', text: "${a} = ''" });

        // …and the emptiness condition it collides with prints the same text and is accepted, which is what
        // makes the refusal above a discrimination rather than a blanket rejection of that string.
        expect(textOf({ kind: 'blank', op: 'eq', subject: field('a') })).toBe("${a} = ''");
    });

    it('refuses an expression that busts the engine’s length budget, via its own self-check', () => {
        // Nothing in the printer counts bytes. It parses what it produced, which is what turns MAX_EXPRESSION_
        // LENGTH, MAX_TOKENS and MAX_PARSE_DEPTH into one refusal instead of three hand-maintained limits.
        const long: Condition = {
            kind: 'group',
            op: 'and',
            children: Array.from({ length: 120 }, (_, i) => ({
                kind: 'compare' as const,
                op: 'eq' as const,
                left: field(`a_very_long_field_key_number_${i}`),
                right: { kind: 'text' as const, value: 'a rather long literal value indeed' },
            })),
        };

        expect(serialize(long).error?.reason).toBe('unparseable');
    });
});

group('normalize keeps the model canonical', () => {
    const compare = (key: string): Condition => ({
        kind: 'compare',
        op: 'eq',
        left: { kind: 'field', key },
        right: { kind: 'number', value: 1 },
    });

    it('flattens a group nested inside a group of the SAME operator', () => {
        // An author who adds an "All of" inside an "All of" has expressed nothing the flat form does not,
        // and text printed from the nested tree would parse back to the flat one — so the printer would
        // refuse its own output if this did not run first.
        const nested: Condition = {
            kind: 'group',
            op: 'and',
            children: [compare('a'), { kind: 'group', op: 'and', children: [compare('b'), compare('c')] }],
        };

        expect(normalize(nested)).toEqual({ kind: 'group', op: 'and', children: [compare('a'), compare('b'), compare('c')] });
        expect(textOf(nested)).toBe('${a} = 1 and ${b} = 1 and ${c} = 1');
    });

    it('keeps a group nested inside a group of the OTHER operator, which is the pair that must not flatten', () => {
        const nested: Condition = {
            kind: 'group',
            op: 'and',
            children: [compare('a'), { kind: 'group', op: 'or', children: [compare('b'), compare('c')] }],
        };

        expect(textOf(nested)).toBe('${a} = 1 and (${b} = 1 or ${c} = 1)');
    });

    it('unwraps a group of one and drops a group of none', () => {
        expect(normalize({ kind: 'group', op: 'and', children: [compare('a')] })).toEqual(compare('a'));
        expect(normalize({ kind: 'group', op: 'and', children: [] })).toBeNull();
        expect(normalize({ kind: 'group', op: 'and', children: [{ kind: 'group', op: 'or', children: [] }] })).toBeNull();
    });

    it('reports an emptied editor as `empty` rather than printing an empty string', () => {
        // The column is nullable and `''` is not the same value as `null` to `hasCondition()` or to
        // `describe()`; the caller writes null.
        expect(serialize({ kind: 'group', op: 'and', children: [] }).error).toEqual({ reason: 'empty' });
    });
});

group('sameCondition compares structure, not serialisation', () => {
    it('sees through key order', () => {
        const a = { kind: 'compare', op: 'gt', left: { kind: 'field', key: 'age' }, right: { kind: 'number', value: 18 } } as Condition;
        const b = { right: { value: 18, kind: 'number' }, left: { key: 'age', kind: 'field' }, op: 'gt', kind: 'compare' } as Condition;

        expect(JSON.stringify(a)).not.toBe(JSON.stringify(b));
        expect(sameCondition(a, b)).toBe(true);
    });

    it('separates the shapes a stringify comparison would also separate, so the case above is not vacuous', () => {
        expect(sameCondition(conditionOf('${a} = 1'), conditionOf('${a} = 2'))).toBe(false);
        expect(sameCondition(conditionOf("${a} = '1'"), conditionOf('${a} = 1'))).toBe(false);
        expect(sameCondition(conditionOf('${a} = 1 and ${b} = 2'), conditionOf('${b} = 2 and ${a} = 1'))).toBe(false);
    });
});
