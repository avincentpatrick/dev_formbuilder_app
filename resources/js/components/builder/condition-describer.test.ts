import { describe as group, expect, it } from 'vitest';
import { describe, type ConditionReading } from './condition-describer';

/*
 * Increment H21d1 — the reading half of the logic canvas (Doc #27 §8).
 *
 * The negative cases carry as much weight as the positive ones, and more of the risk. A describer that
 * over-reaches produces a confident wrong sentence about a form that decides who gets asked what, and §8
 * names exactly that — a silent rewrite or a silent lock-out — as the disqualifier. So every "this is
 * opaque" test below is a specification, not a TODO: the shape is opaque because a reading of it would be
 * wrong or useless, and a future change that starts describing it must change the test deliberately.
 */

const LABELS = { age: 'Your age', tier: 'Membership tier', roster: 'Household members', colours: 'Favourite colours' };

/** The prose of a reading that must be described; fails loudly rather than returning undefined. */
function prose(expression: string): string {
    const reading = describe(expression, LABELS);
    if (reading.status !== 'described') {
        throw new Error(`expected a description, got ${reading.status} for: ${expression}`);
    }

    return reading.prose;
}

function statusOf(expression: string): ConditionReading['status'] {
    return describe(expression, LABELS).status;
}

group('a condition it understands', () => {
    it('reads a comparison against a literal, in the author’s own labels', () => {
        expect(prose("${age} > 18")).toBe('Shown when Your age is more than 18.');
        expect(prose("${tier} = 'gold'")).toBe('Shown when Membership tier is “gold”.');
        expect(prose("${tier} != 'gold'")).toBe('Shown when Membership tier is not “gold”.');
        expect(prose("${age} >= 18")).toBe('Shown when Your age is at least 18.');
        expect(prose("${age} <= 65")).toBe('Shown when Your age is at most 65.');
        expect(prose("${age} < 65")).toBe('Shown when Your age is less than 65.');
    });

    it('says "is blank" for the emptiness test rather than «is “”»', () => {
        expect(prose("${tier} = ''")).toBe('Shown when Membership tier is blank.');
        expect(prose("${tier} != ''")).toBe('Shown when Membership tier is not blank.');
    });

    it('reads a membership predicate and its negation', () => {
        expect(prose("selected(${colours}, 'red')")).toBe('Shown when Favourite colours includes “red”.');
        expect(prose("not(selected(${colours}, 'red'))")).toBe('Shown when Favourite colours does not include “red”.');
    });

    it('reads count() over a repeat group — the branch H21a made work at all', () => {
        // `count(${section})` read 0 in every relevance expression before H21a (Doc #27 §3.3), so this is
        // the shape most likely to be new to an author reading their own form back.
        expect(prose("count(${roster}) > 0")).toBe('Shown when Household members has more than 0 entries.');
        expect(prose("count(${roster}) >= 2")).toBe('Shown when Household members has at least 2 entries.');
        expect(prose("count(${roster}) = 1")).toBe('Shown when Household members has exactly 1 entry.');
        expect(prose("count(${roster}) < 3")).toBe('Shown when Household members has fewer than 3 entries.');
    });

    it('reads a comparison of two fields, which is an ordinary date-range shape', () => {
        // Written first as an opaque case and CORRECTED: `${end} > ${start}` is both legitimate and
        // unambiguous in prose, and refusing it would have hidden a common condition behind raw text for no
        // gain. (It is worth knowing that ordering is numeric-only in both engines, so a date comparison is
        // always false — Doc #27 amendment A1 — but that is the engine's story to tell, not the reader's.)
        expect(prose('${age} > ${tier}')).toBe('Shown when Your age is more than Membership tier.');
    });

    it('chains and/or', () => {
        expect(prose("${age} > 18 and ${tier} = 'gold'")).toBe(
            'Shown when Your age is more than 18 and Membership tier is “gold”.',
        );
        expect(prose("${age} > 18 and ${tier} = 'gold' and ${age} < 65")).toBe(
            'Shown when Your age is more than 18 and Membership tier is “gold” and Your age is less than 65.',
        );
    });

    it('PRESERVES grouping instead of flattening it, in both directions', () => {
        // The failure this pins is a confident wrong answer: `(A or B) and C` and `A or (B and C)` are
        // different conditions, and a reading that renders both as "A or B and C" is worse than none.
        expect(prose("(${age} > 18 or ${age} < 5) and ${tier} = 'gold'")).toBe(
            'Shown when (Your age is more than 18 or Your age is less than 5) and Membership tier is “gold”.',
        );
        expect(prose("${age} > 18 or (${age} < 5 and ${tier} = 'gold')")).toBe(
            'Shown when Your age is more than 18 or (Your age is less than 5 and Membership tier is “gold”).',
        );
    });

    it('falls back to the KEY when a label is missing, because a dangling reference must be findable', () => {
        // An unresolvable key is a real publish-gate refusal. "unknown field" would hide the one piece of
        // information that lets the author go and fix it.
        expect(prose("${no_such_field} = 'x'")).toBe('Shown when no_such_field is “x”.');

        // …and the same key renders as itself when the lookup is empty, which is the no-labels case rather
        // than the unknown-key one. Both must degrade to the key; neither may degrade to a placeholder.
        const bare = describe('${age} > 18', {});
        expect(bare.status === 'described' && bare.prose).toBe('Shown when age is more than 18.');
        // Anti-vacuity: with the labels present it really does use them, so the case above is a fallback
        // and not simply what this module always does.
        expect(prose('${age} > 18')).toBe('Shown when Your age is more than 18.');
    });

    it('collects the keys it names, in first-appearance order and without repeats', () => {
        const reading = describe("${age} > 18 and ${tier} = 'gold' and ${age} < 65", LABELS);
        expect(reading.status).toBe('described');
        expect(reading.status === 'described' && reading.references).toEqual(['age', 'tier']);
    });
});

group('a condition it refuses to paraphrase', () => {
    it.each([
        ['arithmetic in an operand', '${age} + 1 > 18'],
        ['a function that returns a value, not a condition', 'if(${age} > 18, 1, 0) = 1'],
        ['int()', 'int(${age}) > 18'],
        ['a clock function', "${tier} = today()"],
        ['not() over a whole chain', "not(${age} > 18 and ${tier} = 'gold')"],
        ['count() on the right-hand side', '0 < count(${roster})'],
        ['count() compared to a field rather than a number', '${age} > count(${roster})'],
        ['a describable clause with ONE undescribable operand', "${tier} = 'gold' and ${age} + 1 > 18"],
    ])('%s stays opaque', (_label, expression) => {
        expect(statusOf(expression)).toBe('opaque');
    });

    it('still collects the references of an opaque condition, so the chips work either way', () => {
        const reading = describe('${age} + 1 > ${roster}', LABELS);
        expect(reading.status).toBe('opaque');
        expect(reading.status === 'opaque' && reading.references).toEqual(['age', 'roster']);
    });

    it('does not partially describe — one bad operand makes the WHOLE reading opaque', () => {
        // The anti-vacuity twin of the chain test above: both halves are individually describable, and the
        // three-clause version proves the refusal survives a longer chain rather than tripping on arity.
        expect(statusOf("${age} > 18 and ${tier} = 'gold'")).toBe('described');
        expect(statusOf("${age} > 18 and ${tier} = 'gold' and int(${age}) > 1")).toBe('opaque');
    });
});

group('a condition that does not parse', () => {
    it('reports the engine’s own slug rather than swallowing it', () => {
        const reading = describe("${age} = = 18", LABELS);
        expect(reading.status).toBe('invalid');
        expect(reading.status === 'invalid' && reading.slug).toBe('unexpected_token');
    });

    it.each([
        ['an unclosed call', 'selected(${colours}'],
        ['a malformed reference', '${ = 1'],
        ['an unterminated string', "${tier} = 'gold"],
        ['an unknown function', 'frobnicate(${age})'],
    ])('%s is invalid, not a crash', (_label, expression) => {
        expect(statusOf(expression)).toBe('invalid');
    });
});

group('no condition at all', () => {
    it.each([
        ['null', null],
        ['empty', ''],
        ['whitespace', '   '],
    ])('%s is blank, which is not the same as opaque', (_label, expression) => {
        expect(describe(expression, LABELS).status).toBe('blank');
    });
});
