/**
 * Sorting a refused field save between the controls the panel marks in place and the words it lists
 * (M128, `R-d001de0c`). The property that matters is that NOTHING IS DROPPED: every key in the server's
 * map is either marked on a control or listed, and a reader can tell two listed refusals apart.
 */

import { describe, expect, it } from 'vitest';

import { describeSavePath, INLINE_FIELD_KEYS, sortFieldSaveErrors } from './field-save-errors';

describe('sortFieldSaveErrors', () => {
    it('puts a key the panel shows a control for on that control, first message only', () => {
        const sorted = sortFieldSaveErrors({
            label: ['The label field is required.', 'A second complaint.'],
            key: ['The key has already been taken.'],
        });

        expect(sorted.inline).toEqual({ label: 'The label field is required.', key: 'The key has already been taken.' });
        expect(sorted.listed).toEqual([]);
    });

    it('lists every other key in the panel\'s words, in the server\'s order', () => {
        const sorted = sortFieldSaveErrors({
            'validations.0.rule_value': ['The rule value must be a number.'],
            'config.options.2.label': ['The label is required.'],
            is_required: ['The selected requiredness is invalid.'],
        });

        expect(sorted.inline).toEqual({});
        expect(sorted.listed.map((issue) => issue.text)).toEqual([
            'Rule 1: The rule value must be a number.',
            'Choice 3: The label is required.',
            'Requiredness: The selected requiredness is invalid.',
        ]);
    });

    it('drops nothing: every key is either marked or listed', () => {
        const errors: Record<string, string[]> = {
            label: ['a'],
            hint: ['b'],
            'config.calculated_formula': ['c'],
            'config.levels.1.key': ['d'],
            'config.content': ['e'],
            something_new: ['f'],
        };

        const sorted = sortFieldSaveErrors(errors);
        const covered = [...Object.keys(sorted.inline), ...sorted.listed.map((issue) => issue.key)];

        expect(covered.sort()).toEqual(Object.keys(errors).sort());
    });

    it('treats a missing map, and a key with no message, as nothing to show', () => {
        expect(sortFieldSaveErrors(undefined)).toEqual({ inline: {}, listed: [] });
        expect(sortFieldSaveErrors({ label: [] })).toEqual({ inline: {}, listed: [] });
    });

    it('marks in place exactly the inputs the panel renders directly', () => {
        expect([...INLINE_FIELD_KEYS].sort()).toEqual([
            'appearance',
            'config.calculated_formula',
            'default_value',
            'hint',
            'indexed_data_type',
            'key',
            'label',
            'placeholder',
        ]);
    });
});

describe('describeSavePath', () => {
    it('names a path the way the panel names the control, falling back to the path itself', () => {
        expect(describeSavePath('validations.3')).toBe('Rule 4');
        expect(describeSavePath('config.rows.0.value')).toBe('Row 1');
        expect(describeSavePath('config.prefill_source')).toBe('Settings');
        expect(describeSavePath('relevant_expression')).toBe('Show this question when');
        expect(describeSavePath('appearance')).toBe('Choice layout');
        expect(describeSavePath('mystery_path')).toBe('mystery_path');
    });

    it("names a note's refused content by the tab that edits it, in the pane's list rather than on a control (M129)", () => {
        const sorted = sortFieldSaveErrors({ 'config.content': ["The note's content is invalid: block 2 has a link this form cannot show."] });

        expect(sorted.inline).toEqual({});
        expect(sorted.listed).toEqual([
            { key: 'config.content', text: "Content: The note's content is invalid: block 2 has a link this form cannot show." },
        ]);
    });
});
