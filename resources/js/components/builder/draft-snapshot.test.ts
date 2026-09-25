/**
 * The draft → snapshot projection (Increment M112, `B6`).
 *
 * Eight draft-only states the published serializer never sees, each asserted separately. The
 * unparsable-expression case is the highest-value one in the file and the reason the module exists in
 * this shape: `safeEvaluate()` (`useFormRuntime.ts:310-319`) catches a parser throw by degrading the
 * WHOLE FORM to everything-relevant and latching `engineFailed` true for the session, so one keystroke
 * mid-condition would otherwise kill relevance with no recovery short of a remount. This suite is the
 * only thing between a keystroke and a poisoned preview.
 */

import { describe as group, expect, it } from 'vitest';

import {
    DRAFT_KEY_PREFIX,
    UNTITLED_LABEL,
    projectDraft,
    shapeOf,
    type DraftProjectionInput,
} from './draft-snapshot';
import type { LocalField, LocalSection } from './types';

function field(overrides: Partial<LocalField> & { uid: string }): LocalField {
    return {
        id: `id-${overrides.uid}`,
        form_section_id: null,
        key: 'q1',
        field_type: 'short_text',
        label: 'Question one',
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
        sequence: 0,
        section_sequence: null,
        version: null,
        validations: [],
        ...overrides,
    };
}

function section(overrides: Partial<LocalSection> & { uid: string }): LocalSection {
    return {
        id: `sid-${overrides.uid}`,
        key: 's1',
        label: 'Section one',
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 0,
        version: null,
        ...overrides,
    };
}

function input(fields: LocalField[], sections: LocalSection[] = []): DraftProjectionInput {
    return {
        form: {
            id: 'form-1',
            title: 'Survey',
            description: null,
            default_locale: 'en',
            supported_locales: ['en'],
        },
        version: { id: 'ver-1', version_number: 1 },
        sections,
        fields,
    };
}

group('the eight draft-only states', () => {
    it('1. mints a synthetic key for a field that has none, and records it', () => {
        const { schema, issues, uidByKey } = projectDraft(input([field({ uid: 'u1', key: '' })]));

        expect(schema.version.schema.fields[0].key).toBe(`${DRAFT_KEY_PREFIX}u1`);
        expect(issues).toHaveLength(1);
        expect(issues[0].code).toBe('missing_key');
        expect(uidByKey[`${DRAFT_KEY_PREFIX}u1`]).toBe('u1');
    });

    it('2. resolves a duplicate key by FIRST-BY-SEQUENCE, keeping both fields', () => {
        // Asserted by LABEL, not by position: an order-blind assertion would pass with the comparator
        // reversed, which is exactly the mutation this case has to see.
        const { schema, issues } = projectDraft(
            input([
                field({ uid: 'late', key: 'colour', label: 'Added second', sequence: 5 }),
                field({ uid: 'early', key: 'colour', label: 'Added first', sequence: 1 }),
            ]),
        );

        const [first, second] = schema.version.schema.fields;

        expect(first.key).toBe('colour');
        expect(first.label).toBe('Added first');
        expect(second.key).toBe(`${DRAFT_KEY_PREFIX}late`);
        expect(second.label).toBe('Added second');

        // Neither field is dropped — the preview must agree with the canvas about how many questions exist.
        expect(schema.version.schema.fields).toHaveLength(2);
        expect(issues.map((i) => i.code)).toContain('duplicate_key');
    });

    it('3. gives an untitled question a legible placeholder, and still records it', () => {
        const { schema, issues } = projectDraft(input([field({ uid: 'u1', label: '' })]));

        expect(schema.version.schema.fields[0].label).toBe(UNTITLED_LABEL);
        // The placeholder alone would be papering over the gap, which is what the module refuses to do.
        expect(issues.map((i) => i.code)).toContain('missing_label');
    });

    it('4. keeps an empty option list EMPTY, because that is the honest preview of the defect', () => {
        const { schema, issues } = projectDraft(
            input([field({ uid: 'u1', field_type: 'dropdown', config: { options: [] } })]),
        );

        expect(schema.version.schema.fields[0].config).toEqual({ options: [] });
        expect(issues.map((i) => i.code)).toContain('empty_option_list');
    });

    it('5. NEVER hands an unparsable expression to the engine', () => {
        // ⛔ THE LOAD-BEARING CASE. A throw from the engine's parser latches engineFailed for the whole
        // session, so a half-typed condition — the normal state of a field being edited — must be
        // filtered out HERE and never reach it.
        const { schema, issues } = projectDraft(
            input([field({ uid: 'u1', relevant_expression: '${age} >' })]),
        );

        expect(schema.version.schema.fields[0].relevant_expression).toBeNull();
        expect(issues.map((i) => i.code)).toContain('unparsable_expression');
    });

    it('5b. leaves a VALID expression untouched, so the filter is not simply always-null', () => {
        const { schema, issues } = projectDraft(
            input([field({ uid: 'u1', relevant_expression: "${age} > '18'" })]),
        );

        expect(schema.version.schema.fields[0].relevant_expression).toBe("${age} > '18'");
        expect(issues).toHaveLength(0);
    });

    it('5c. treats a blank expression as no condition rather than as a failure', () => {
        const { schema, issues } = projectDraft(input([field({ uid: 'u1', relevant_expression: '   ' })]));

        expect(schema.version.schema.fields[0].relevant_expression).toBeNull();
        expect(issues).toHaveLength(0);
    });

    it('6. passes a half-built cascade through as-is, without second-guessing it', () => {
        const partial = { levels: [{ key: 'province' }], options: [] };
        const { schema } = projectDraft(
            input([field({ uid: 'u1', field_type: 'cascading_select', config: partial })]),
        );

        expect(schema.version.schema.fields[0].config).toEqual(partial);
    });

    it('7. omits a calculated field’s formula KEY when there is no formula', () => {
        // Absent, not null: the engine branches on presence.
        const { schema, issues } = projectDraft(
            input([field({ uid: 'u1', field_type: 'calculated', config: { formula: '  ' } })]),
        );

        expect('formula' in (schema.version.schema.fields[0].config ?? {})).toBe(false);
        expect(issues.map((i) => i.code)).toContain('missing_formula');
    });

    it('8. orders fields by sequence and sections by their own sequence', () => {
        const { schema } = projectDraft(
            input(
                [
                    field({ uid: 'c', key: 'c', sequence: 3 }),
                    field({ uid: 'a', key: 'a', sequence: 1 }),
                    field({ uid: 'b', key: 'b', sequence: 2 }),
                ],
                [
                    section({ uid: 's2', id: 'sid-s2', key: 'second', sequence: 2 }),
                    section({ uid: 's1', id: 'sid-s1', key: 'first', sequence: 1 }),
                ],
            ),
        );

        expect(schema.version.schema.fields.map((f) => f.key)).toEqual(['a', 'b', 'c']);
        expect(schema.version.schema.sections.map((s) => s.key)).toEqual(['first', 'second']);
    });
});

group('the wire shape', () => {
    it('synthesizes every column the runtime requires that the builder does not carry', () => {
        // ⚠️ Measured at source: BuilderPresenter emits no *_translations key at all, so these are
        // synthesized rather than mapped — and the preview therefore renders the default locale only.
        const { schema } = projectDraft(
            input([field({ uid: 'u1', validations: [{ rule_type: 'pattern', operator: null, rule_value: 'a+', expression: null, error_message: 'nope', related_field_key: null, sequence: 0 }] })]),
        );

        const f = schema.version.schema.fields[0];

        expect(f.label_translations).toBeNull();
        expect(f.hint_translations).toBeNull();
        expect(f.default_value_is_expression).toBe(false);
        expect(f.validations[0].error_message_translations).toBeNull();
        expect(f.validations[0].logic_group_ordinal).toBeNull();
        expect(f.validations[0].logic_operator).toBeNull();
        expect(f.validations[0].rule_value).toBe('a+');
    });

    it('resolves a field’s section by key, and flags one that points nowhere', () => {
        const s = section({ uid: 's1', id: 'sid-1', key: 'demographics' });
        const ok = projectDraft(input([field({ uid: 'u1', form_section_id: 'sid-1' })], [s]));
        expect(ok.schema.version.schema.fields[0].section_key).toBe('demographics');
        expect(ok.issues).toHaveLength(0);

        const orphan = projectDraft(input([field({ uid: 'u2', form_section_id: 'sid-gone' })], [s]));
        expect(orphan.schema.version.schema.fields[0].section_key).toBeNull();
        expect(orphan.issues.map((i) => i.code)).toContain('unknown_section');
    });

    it('narrows an unrecognised requiredness to optional rather than casting it blindly', () => {
        const { schema } = projectDraft(input([field({ uid: 'u1', is_required: 'nonsense' })]));

        expect(schema.version.schema.fields[0].is_required).toBe('optional');
    });
});

group('shape — what makes a live preview affordable', () => {
    it('does NOT change when only a label, hint or placeholder changes', () => {
        // ⛔ THE PROPERTY B7 DEPENDS ON. If this ever fails, the preview remounts the whole runtime on
        // every keystroke, which is the cost that makes the feature unaffordable.
        const before = projectDraft(input([field({ uid: 'u1', label: 'Before', hint: null, placeholder: null })]));
        const after = projectDraft(input([field({ uid: 'u1', label: 'After', hint: 'A hint', placeholder: 'Type here' })]));

        expect(after.shape).toBe(before.shape);
    });

    it('DOES change when a column the engine reads changes', () => {
        // The paired positive: without it the case above passes trivially for a constant shape.
        const base = projectDraft(input([field({ uid: 'u1', relevant_expression: null })]));

        expect(projectDraft(input([field({ uid: 'u1', is_required: 'required' })])).shape).not.toBe(base.shape);
        expect(projectDraft(input([field({ uid: 'u1', relevant_expression: "${a} = '1'" })])).shape).not.toBe(base.shape);
        expect(projectDraft(input([field({ uid: 'u1', sequence: 7 })])).shape).not.toBe(base.shape);
    });

    it('changes on an option VALUE but not on an option LABEL', () => {
        const withValues = (labels: [string, string]) =>
            projectDraft(
                input([
                    field({
                        uid: 'u1',
                        field_type: 'dropdown',
                        config: { options: [{ value: 'a', label: labels[0] }, { value: 'b', label: labels[1] }] },
                    }),
                ]),
            ).shape;

        expect(withValues(['Apple', 'Banana'])).toBe(withValues(['Aubergine', 'Bean']));

        const relabelled = withValues(['Apple', 'Banana']);
        const revalued = projectDraft(
            input([field({ uid: 'u1', field_type: 'dropdown', config: { options: [{ value: 'a', label: 'Apple' }, { value: 'c', label: 'Banana' }] } })]),
        ).shape;

        expect(revalued).not.toBe(relabelled);
    });

    it('is derivable from a snapshot alone', () => {
        const { schema, shape } = projectDraft(input([field({ uid: 'u1' })]));

        expect(shapeOf(schema.version.schema)).toBe(shape);
    });
});

group('the governing rule', () => {
    it('never throws, whatever the draft holds', () => {
        expect(() =>
            projectDraft(
                input([
                    field({ uid: 'a', key: '', label: '', relevant_expression: '((((' }),
                    field({ uid: 'b', key: '', label: '', field_type: 'calculated', config: { formula: '' }, sequence: 1 }),
                    field({ uid: 'c', key: 'a', field_type: 'matrix', config: { rows: [], columns: [] }, sequence: 2, form_section_id: 'gone' }),
                ]),
            ),
        ).not.toThrow();
    });

    it('omits no field for being incomplete', () => {
        const { schema } = projectDraft(
            input([
                field({ uid: 'a', key: '', label: '' }),
                field({ uid: 'b', key: '', label: '', sequence: 1 }),
                field({ uid: 'c', key: '', label: '', sequence: 2 }),
            ]),
        );

        expect(schema.version.schema.fields).toHaveLength(3);
    });
});
