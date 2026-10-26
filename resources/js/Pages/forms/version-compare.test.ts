import { describe, expect, it } from 'vitest';
import type { BuilderEnums } from '@/components/builder/types';
import { breakingWords, changeLines, counts, groups, summary, type CompareQuestion, type CompareSection, type Comparison, type CompareWords } from './version-compare';

/*
 * M189 (`version-diff-ui`, `D169`) — the Compare page's reading of a comparison: groups in the order a respondent meets
 * them, the two filters, and every setting in the builder's own words, each side with its own version's labels.
 */

const enums = {
    required_modes: [
        { value: 'optional', label: 'Optional' },
        { value: 'required', label: 'Required' },
        { value: 'conditional', label: 'Conditional' },
    ],
    indexed_data_types: [],
    validation_rule_types: [],
    comparison_operators: [],
} as unknown as BuilderEnums;

const words: CompareWords = { enums, type_labels: { short_text: 'Short text', integer: 'Number' } };

function question(overrides: Partial<CompareQuestion>): CompareQuestion {
    return { key: 'q', label: 'Q', field_type: 'short_text', section_key: 'hh', status: 'same', changes: [], breaking: null, marks: [], ...overrides };
}

function section(overrides: Partial<CompareSection>): CompareSection {
    return { key: 'hh', label: 'Household', status: 'same', changes: [], marks: [], ...overrides };
}

const comparison: Comparison = {
    sections: [section({}), section({ key: 'visit', label: 'Visit', status: 'changed', changes: [{ setting: 'label', before: 'The visit', after: 'Visit' }] }), section({ key: 'old', label: 'Old', status: 'removed', marks: ['comparability'] })],
    questions: [
        question({ key: 'top', label: 'Consent', section_key: null }),
        question({ key: 'name', label: 'Name', status: 'changed', changes: [{ setting: 'is_required', before: 'optional', after: 'required' }], breaking: 'required', marks: ['comparability'] }),
        question({ key: 'age', label: 'Age', status: 'same' }),
        question({ key: 'notes', label: 'Notes', section_key: 'visit', status: 'added' }),
        question({ key: 'gone', label: 'Gone', section_key: 'old', status: 'removed', breaking: 'removed', marks: ['comparability'] }),
    ],
};

describe('version compare — groups and filters', () => {
    it('hides what is the same, keeps a changed section with no changed question, and the top level first', () => {
        const shown = groups(comparison, { onlyMarked: false, showUnchanged: false });

        expect(shown.map((g) => [g.key, g.questions.map((q) => q.key)])).toEqual([
            ['hh', ['name']],
            ['visit', ['notes']],
            ['old', ['gone']],
        ]);
    });

    it('shows everything, in order, when unchanged questions are asked for', () => {
        const shown = groups(comparison, { onlyMarked: false, showUnchanged: true });

        expect(shown.map((g) => g.key)).toEqual([null, 'hh', 'visit', 'old']);
        expect(shown[0].label).toBe('Not in a section');
        expect(shown[1].questions.map((q) => q.key)).toEqual(['name', 'age']);
    });

    it('keeps only marked changes when asked, whatever the unchanged switch says', () => {
        const shown = groups(comparison, { onlyMarked: true, showUnchanged: true });

        expect(shown.map((g) => [g.key, g.questions.map((q) => q.key)])).toEqual([
            ['hh', ['name']],
            ['old', ['gone']],
        ]);
    });

    it('keeps a question whose section the comparison does not know, in a group of its own', () => {
        const stray: Comparison = { sections: [], questions: [question({ key: 'x', section_key: 'ghost', status: 'added' })] };

        expect(groups(stray, { onlyMarked: false, showUnchanged: false }).map((g) => [g.key, g.label])).toEqual([['ghost', 'ghost']]);
    });
});

describe('version compare — words', () => {
    it('words each setting as the builder does, each side with its own version’s labels', () => {
        const lines = changeLines(
            'question',
            [
                { setting: 'is_required', before: 'optional', after: 'required' },
                { setting: 'field_type', before: 'short_text', after: 'integer' },
                { setting: 'relevant_expression', before: '${age} >= 18', after: '${age} >= 18' },
            ],
            words,
            { from: { age: 'Age' }, to: { age: 'Age in years' } },
        );

        expect(lines[0]).toEqual({ setting: 'Required', from: 'Optional', to: 'Required' });
        expect(lines[1]).toEqual({ setting: 'Question type', from: 'Short text', to: 'Number' });
        expect(lines[2].from).toContain('Age');
        expect(lines[2].from).not.toContain('Age in years');
        expect(lines[2].to).toContain('Age in years');
    });

    it('words translations, a place, a value too large and a section setting', () => {
        const question = changeLines(
            'question',
            [
                { setting: 'label_translations', before: { fil: 'Pangalan' }, after: null },
                { setting: 'position', before: { section: 'Household', position: 3 }, after: { section: 'Household', position: 1 } },
                { setting: 'config.options', before: null, after: null, too_large: true },
            ],
            words,
            { from: {}, to: {} },
        );
        const sectionLines = changeLines('section', [{ setting: 'is_repeatable', before: false, after: true }], words, { from: {}, to: {} });

        expect(question).toEqual([
            { setting: 'Label, other languages', from: 'fil: Pangalan', to: 'None' },
            { setting: 'Place', from: '“Household”, place 3', to: '“Household”, place 1' },
            { setting: 'Choices', from: 'Too large to show', to: 'Too large to show' },
        ]);
        expect(sectionLines).toEqual([{ setting: 'Repeatable group', from: 'No', to: 'Yes' }]);
    });

    it('says why answers may not compare, and counts and sums up the questions', () => {
        expect(breakingWords('required')).toBe('it became required');
        expect(breakingWords(null)).toBeNull();

        const tally = counts(comparison);
        expect(tally).toEqual({ changed: 1, added: 1, removed: 1, same: 2, marked: 2 });
        expect(summary(tally)).toBe('Questions: 1 changed, 1 added, 1 removed and 2 unchanged.');
        expect(summary({ changed: 0, added: 0, removed: 0, same: 4, marked: 0 })).toBe('No questions differ — all 4 are the same.');
    });
});
