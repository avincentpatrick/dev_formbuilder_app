/**
 * M243 (`R-1da063f4`) — the answer limits as the respondent meets them: the word count's wording, what ticking does, which
 * boxes lock, and the note under a multi-select. The engines are pinned by `tests/golden/validation/answer_rules.json`;
 * this pins the screen.
 */
import { describe, expect, it } from 'vitest';
import { choiceLocked, choiceNote, nextChoices, wordCount, wordCounterText } from './answer-limits';

const NONE = new Set<string>();
const NOVIEW = new Set(['noview']);

describe('the word count under a text box (M243)', () => {
    it('counts with the engine, so a no-break space separates and a zero-width one does not', () => {
        expect(wordCount('one two\tthree')).toBe(3);
        expect(wordCount(`one${String.fromCharCode(0xa0)}two`)).toBe(2);
        expect(wordCount(`one${String.fromCharCode(0xfeff)}two`)).toBe(1);
        expect(wordCount('   ')).toBe(0);
    });

    it.each<[string, number, { min: number | null; max: number | null }, string]>([
        ['a maximum', 12, { min: null, max: 1500 }, '12 of 1,500 words'],
        ['a maximum, gone over', 1512, { min: null, max: 1500 }, '1,512 of 1,500 words: 12 too many'],
        ['a minimum', 1, { min: 50, max: null }, '1 word (at least 50)'],
        ['both', 12, { min: 10, max: 20 }, '12 words (10 to 20)'],
        ['both, gone over', 21, { min: 10, max: 20 }, '21 words (10 to 20): 1 too many'],
    ])('says it plainly with %s', (_name, count, bounds, expected) => {
        expect(wordCounterText(count, bounds)).toBe(expected);
    });
});

describe('ticking a multi-select with limits (M243)', () => {
    it('adds and removes a choice as before', () => {
        expect(nextChoices(['a'], 'b', true, NONE)).toEqual(['a', 'b']);
        expect(nextChoices(['a', 'b'], 'a', false, NONE)).toEqual(['b']);
    });

    it('clears every other choice when an only answer is ticked', () => {
        expect(nextChoices(['a', 'b'], 'noview', true, NOVIEW)).toEqual(['noview']);
    });

    it('locks the other boxes once the most are ticked, but never a ticked box or an only answer', () => {
        expect(choiceLocked('c', ['a', 'b'], 2, NOVIEW)).toBe(true);
        expect(choiceLocked('a', ['a', 'b'], 2, NOVIEW)).toBe(false);
        expect(choiceLocked('noview', ['a', 'b'], 2, NOVIEW)).toBe(false);
        expect(choiceLocked('c', ['a'], 2, NOVIEW)).toBe(false);
    });

    it('locks every ordinary box while an only answer is ticked', () => {
        expect(choiceLocked('a', ['noview'], null, NOVIEW)).toBe(true);
        expect(choiceLocked('a', [], null, NOVIEW)).toBe(false);
    });
});

describe('the note under a multi-select (M243)', () => {
    const label = (value: string): string => (value === 'noview' ? 'No view or opinion' : value.toUpperCase());

    it.each<[string, string[], { min: number | null; max: number | null } | null, string | null]>([
        ['no limits', [], null, null],
        ['a maximum', ['a'], { min: null, max: 2 }, 'Choose up to 2.'],
        ['a minimum', [], { min: 2, max: null }, 'Choose at least 2.'],
        ['both', [], { min: 2, max: 3 }, 'Choose 2 to 3.'],
        ['an exact number', [], { min: 2, max: 2 }, 'Choose 2.'],
        ['the maximum reached', ['a', 'b'], { min: null, max: 2 }, '2 of 2 chosen. Untick one to choose another.'],
        ['more than the maximum, from a saved answer', ['a', 'b', 'c'], { min: null, max: 2 }, '3 chosen, and at most 2 are allowed. Untick 1.'],
    ])('with %s', (_name, current, choices, expected) => {
        expect(choiceNote(current, choices, NOVIEW, label)).toBe(expected);
    });

    it('says why the boxes are locked while an only answer stands alone', () => {
        expect(choiceNote(['noview'], { min: null, max: 2 }, NOVIEW, label)).toBe('“No view or opinion” is chosen on its own. Untick it to choose others.');
    });
});
