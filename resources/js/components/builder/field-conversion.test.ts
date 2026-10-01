import { describe, expect, it } from 'vitest';

import { conversionPlan, ENUMS, PALETTE, serverField, serverSection } from './builder-store-fixtures';
import {
    censusOwner,
    consequenceView,
    GROUP_NOT_ASKED,
    GROUP_QUESTION_TYPES,
    needsReview,
    staleMessage,
    targetGroups,
    type ConsequenceContext,
} from './field-conversion';
import type { LocalField, LocalSection, PaletteType } from './types';

const paletteByValue = new Map(PALETTE.flatMap((group) => group.types.map((type) => [type.value, type] as const)));

function ctx(overrides: Partial<ConsequenceContext> = {}): ConsequenceContext {
    return {
        enums: ENUMS,
        fields: [{ ...serverField({ key: 'age', label: 'Your age' }), uid: 'u1' } as LocalField],
        sections: [{ ...serverSection({ key: 'adults', label: 'Adults only' }), uid: 'u2' } as LocalSection],
        ownKey: 'age',
        labelOf: (type) => paletteByValue.get(type)?.label ?? type,
        ...overrides,
    };
}

describe('targetGroups', () => {
    it('keeps the server order and splits the kinds a respondent answers from the kinds they do not (FC1)', () => {
        const groups = targetGroups(
            [conversionPlan({ to: 'phone' }), conversionPlan({ to: 'long_text' }), conversionPlan({ to: 'note' }), conversionPlan({ to: 'hidden' })],
            paletteByValue,
        );

        expect(groups).toEqual([
            { label: GROUP_QUESTION_TYPES, options: [{ value: 'phone', label: 'Phone' }, { value: 'long_text', label: 'Long text' }] },
            { label: GROUP_NOT_ASKED, options: [{ value: 'note', label: 'Note / label' }, { value: 'hidden', label: 'Hidden field' }] },
        ]);
    });

    it('omits an empty group, and offers nothing for a question that converts to nothing (FC2)', () => {
        expect(targetGroups([conversionPlan({ to: 'note' })], paletteByValue)).toEqual([
            { label: GROUP_NOT_ASKED, options: [{ value: 'note', label: 'Note / label' }] },
        ]);
        expect(targetGroups([], paletteByValue)).toEqual([]);
    });

    it('reads "not asked" from the palette’s transmitted facts, never from a list of type names (FC3)', () => {
        const banner: PaletteType = { value: 'banner', label: 'Banner', advanced: false, has_options: false, config_editor: null, value_shape: 'no_answer' };
        const groups = targetGroups([conversionPlan({ to: 'banner' })], new Map([['banner', banner]]));

        expect(groups).toEqual([{ label: GROUP_NOT_ASKED, options: [{ value: 'banner', label: 'Banner' }] }]);
    });
});

describe('needsReview', () => {
    it('asks for review when the plan says so OR when another question changes meaning (FC4)', () => {
        expect(needsReview(conversionPlan({ requires_confirmation: true }))).toBe(true);
        expect(needsReview(conversionPlan({ census: [{ code: 'list_meaning_changes', site: 'relevance', key: 'x', message: 'm' }] }))).toBe(true);
        expect(needsReview(conversionPlan())).toBe(false);
    });
});

describe('consequenceView', () => {
    it('names each lost setting in words, with a fallback for a key it does not know (FC5)', () => {
        const view = consequenceView(conversionPlan({ config_dropped: ['options', 'mystery'], requires_confirmation: true }), ctx());

        expect(view.question).toEqual(['Its choices are removed.', 'Its “mystery” setting is removed.']);
    });

    it('names each column change in words, using the transmitted labels (FC6)', () => {
        const view = consequenceView(
            conversionPlan({
                requires_confirmation: true,
                changes: [
                    { column: 'is_required', from: 'conditional', to: 'optional' },
                    { column: 'default_value', from: 'hello', to: null },
                    { column: 'default_value_is_expression', from: true, to: false },
                    { column: 'is_queryable', from: true, to: false },
                    { column: 'indexed_data_type', from: 'text', to: null },
                ],
            }),
            ctx(),
        );

        expect(view.question).toEqual([
            'Its requiredness changes from Conditional to Optional.',
            'Its default value “hello” is removed.',
            'Its default is no longer a formula.',
            'It is no longer indexed for reporting.',
            'Its reporting data type (Text) is cleared.',
        ]);
    });

    it('lists removed rules with the server’s reason, added rules, and how many stay (FC7)', () => {
        const view = consequenceView(
            conversionPlan({
                requires_confirmation: true,
                kept: [{ sequence: 1, rule_type: 'min_length', operator: null, rule_value: '2', expression: null, error_message: 'Too short.' }],
                dropped: [{ sequence: 0, rule_type: 'pattern', operator: null, rule_value: 'abc', expression: null, error_message: 'Bad.', reason: 'type_default', reason_message: 'It was the old type’s built-in check.' }],
                added: [{ sequence: 2, rule_type: 'pattern', rule_value: 'xyz', error_message: 'Enter a valid phone number.' }],
            }),
            ctx(),
        );

        expect(view.removed).toEqual([{ label: 'Must match a pattern', value: 'abc', shows: 'Bad.', reason: 'It was the old type’s built-in check.' }]);
        expect(view.added).toEqual([{ label: 'Must match a pattern', value: 'xyz', shows: 'Enter a valid phone number.' }]);
        expect(view.kept).toBe('1 existing validation rule stays unchanged.');
    });

    it('passes the server’s warnings through verbatim and in order (FC8)', () => {
        const view = consequenceView(
            conversionPlan({ requires_confirmation: true, warnings: [{ code: 'b', message: 'Second.' }, { code: 'a', message: 'First.' }] }),
            ctx(),
        );

        expect(view.warnings).toEqual(['Second.', 'First.']);
    });

    it('says nothing is lost only for a plan that needs no review (FC11)', () => {
        expect(consequenceView(conversionPlan(), ctx()).summary).toContain('Nothing is lost');
        expect(consequenceView(conversionPlan({ to: 'phone', requires_confirmation: true }), ctx()).summary).toBe('Here is what changing it to Phone does.');
    });
});

describe('censusOwner', () => {
    it('names the owner of each kind of reference (FC9)', () => {
        const c = ctx({ ownKey: 'score' });

        expect(censusOwner({ code: 'x', site: 'relevance', key: 'age', message: '' }, c)).toBe('The condition on “Your age”');
        expect(censusOwner({ code: 'x', site: 'relevance', key: 'score', message: '' }, c)).toBe('This question’s own condition');
        expect(censusOwner({ code: 'x', site: 'section_relevance', key: 'adults', message: '' }, c)).toBe('The condition on the section “Adults only”');
        expect(censusOwner({ code: 'x', site: 'formula', key: 'age', message: '' }, c)).toBe('The formula of “Your age”');
        expect(censusOwner({ code: 'x', site: 'constraint', key: 'score', message: '' }, c)).toBe('A custom check on this question');
        expect(censusOwner({ code: 'x', site: 'rule', key: 'age', message: '' }, c)).toBe('A rule on “Your age”');
    });

    it('resolves a template hole to a field, a section, the thank-you message, or both when a key is shared (FC10)', () => {
        const shared = ctx({
            fields: [{ ...serverField({ key: 'intro', label: '   ' }), uid: 'u1' } as LocalField],
            sections: [{ ...serverSection({ key: 'intro', label: 'Welcome' }), uid: 'u2' } as LocalSection],
        });

        expect(censusOwner({ code: 'x', site: 'template', key: 'age', message: '' }, ctx())).toBe('The text of “Your age”');
        expect(censusOwner({ code: 'x', site: 'template', key: 'confirmation_message', message: '' }, ctx())).toBe('The thank-you message');
        expect(censusOwner({ code: 'x', site: 'template', key: 'intro', message: '' }, shared)).toBe(
            'The text of “intro”, or The title or description of the section “Welcome”',
        );
        expect(censusOwner({ code: 'x', site: 'template', key: 'gone', message: '' }, ctx())).toBe('Text that mentions this question (“gone”)');
    });
});

describe('staleMessage', () => {
    it('says what the question is now, and asks for a new choice only when the old one is gone', () => {
        expect(staleMessage('Email', true)).toBe('It is now Email. Here is what changing it does now.');
        expect(staleMessage(null, false)).toBe('Here is what changing its type does now. The type you chose is no longer offered, so choose again.');
    });
});
