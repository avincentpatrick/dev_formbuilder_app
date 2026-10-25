import { describe, expect, it } from 'vitest';

import { conflictDifferences, settingWords, type DiffWords } from './conflict-diff';
import type { LocalField, LocalSection, ServerField, ServerSection } from './types';

/**
 * M176 (`R-164579e8`) — the testers' "This item changed elsewhere" showed two identical columns because the difference
 * was the Required setting and the dialog printed only label and key. Each case names what the dialog must now say.
 */

const WORDS: DiffWords = {
    enums: {
        required_modes: [
            { value: 'optional', label: 'Optional' },
            { value: 'required', label: 'Required' },
        ],
        indexed_data_types: [{ value: 'text', label: 'Text' }],
        validation_rule_types: [
            { value: 'min_value', label: 'Minimum value' } as never,
            { value: 'max_length', label: 'Maximum length' } as never,
        ],
        comparison_operators: [],
    },
    labels: { age: 'Your age' },
    typeLabel: (value) => ({ short_text: 'Short text', long_text: 'Long text' })[value] ?? value,
};

function serverField(overrides: Partial<ServerField> = {}): ServerField {
    return {
        id: 'f1',
        form_section_id: null,
        key: 'occupation',
        field_type: 'short_text',
        label: 'Occupation',
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: {},
        default_value: null,
        is_pii: false,
        is_sensitive: false,
        is_queryable: false,
        indexed_data_type: null,
        sequence: 1,
        section_sequence: 1,
        version: 'v2',
        validations: [],
        ...overrides,
    } as ServerField;
}

function mine(overrides: Partial<ServerField> = {}): LocalField {
    return { ...serverField({ version: 'v1', ...overrides }), uid: 'u1' } as LocalField;
}

describe('conflictDifferences', () => {
    it('names the Required setting when that is all that differs — the testers\' screenshot', () => {
        expect(conflictDifferences('field', mine({ is_required: 'required' }), serverField(), WORDS)).toEqual([
            { setting: 'Required', mine: 'Required', theirs: 'Optional' },
        ]);
    });

    it('lists nothing when only the save token moved', () => {
        expect(conflictDifferences('field', mine(), serverField(), WORDS)).toEqual([]);
    });

    it('reads a config the database returned in its own key order as the same config', () => {
        const a = mine({ config: { options: [{ value: 'a', label: 'A' }], layout: 'list' } as never });
        const b = serverField({ config: { layout: 'list', options: [{ label: 'A', value: 'a' }] } as never });

        expect(conflictDifferences('field', a, b, WORDS)).toEqual([]);
    });

    it('names choices by their labels, and several settings in the panel\'s order', () => {
        const a = mine({ label: 'Job', config: { options: [{ value: 'a', label: 'Farmer' }, { value: 'b', label: 'Teacher' }] } as never });
        const b = serverField({ config: { options: [{ value: 'a', label: 'Farmer' }] } as never });

        expect(conflictDifferences('field', a, b, WORDS)).toEqual([
            { setting: 'Label', mine: 'Job', theirs: 'Occupation' },
            { setting: 'Choices', mine: 'Farmer, Teacher', theirs: 'Farmer' },
        ]);
    });

    it('says a rule in words, and an empty value as Empty', () => {
        const rule = { rule_type: 'min_value', operator: null, rule_value: '5', expression: null, error_message: null, related_field_key: null, sequence: 0 };
        const zero = { ...rule, rule_value: '0', sequence: 1 };
        const a = mine({ hint: 'Count everyone.', validations: [rule, zero] as never });

        expect(conflictDifferences('field', a, serverField(), WORDS)).toEqual([
            { setting: 'Help text', mine: 'Count everyone.', theirs: 'Empty' },
            // M176 (`R-61d43278`): a minimum of 0 is named as the Validation tab names it.
            { setting: 'Rules', mine: '1. Minimum value 5; 2. No negative numbers', theirs: 'No rules' },
        ]);
    });

    it('reads a rule with no group key as the same rule the server sends back with a null group', () => {
        const rule = { rule_type: 'min_value', operator: null, rule_value: '0', expression: null, error_message: null, related_field_key: null, sequence: 0 };

        expect(
            conflictDifferences('field', mine({ validations: [rule] as never }), serverField({ validations: [{ ...rule, logic_group: null, logic_operator: null }] as never }), WORDS),
        ).toEqual([]);
    });

    it('says a type change by the palette\'s names', () => {
        expect(conflictDifferences('field', mine({ field_type: 'long_text' }), serverField(), WORDS)[0]).toEqual({
            setting: 'Question type',
            mine: 'Long text',
            theirs: 'Short text',
        });
    });

    it('compares a section by the section panel\'s names', () => {
        const server: ServerSection = {
            id: 's1', key: 'adults', label: 'Adults only', description: null, is_repeatable: false, min_instances: null,
            max_instances: null, relevant_expression: null, sequence: 1, version: 'v2',
        } as ServerSection;
        const local = { ...server, label: 'Grown-ups', is_repeatable: true, version: 'v1', uid: 'u2' } as LocalSection;

        expect(conflictDifferences('section', local, server, WORDS)).toEqual([
            { setting: 'Section title', mine: 'Grown-ups', theirs: 'Adults only' },
            { setting: 'Repeatable group', mine: 'Yes', theirs: 'No' },
        ]);
    });
});

/** M188 (`R-3894369d`) — the History tab words each recorded change through the same function the dialog now uses. */
describe('settingWords', () => {
    it('names a file question’s limits by the keys the Media tab writes — they were three keys no question has', () => {
        expect(settingWords('config.max_file_size_bytes', 1048576, 2097152, WORDS).setting).toBe('File size limit');
        expect(settingWords('config.max_count', 1, 3, WORDS).setting).toBe('Most files');
        expect(settingWords('config.min_count', null, 1, WORDS)).toEqual({ setting: 'Fewest files', a: 'Empty', b: '1' });
    });

    it('words a move as a place, and the rules as rules', () => {
        expect(settingWords('position', { section: null, position: 4 }, { section: 'Household', position: 1 }, WORDS)).toEqual({
            setting: 'Place',
            a: 'Not in a section, place 4',
            b: '“Household”, place 1',
        });
        expect(settingWords('validations', [], [], WORDS)).toEqual({ setting: 'Rules', a: 'No rules', b: 'No rules' });
    });
});
