/**
 * The draft → snapshot projection (Increment M112, `B6`).
 *
 * Eight draft-only states the published serializer never sees, each asserted separately, and a ninth
 * (M145) for the rule rows. The unparsable-expression case is the highest-value one in the file and the
 * reason the module exists in this shape: `safeEvaluate()` in `useFormRuntime.ts` catches an engine throw by
 * degrading the WHOLE FORM to everything-relevant and setting `engineFailed`, so one keystroke
 * mid-condition would otherwise kill relevance — silently, because the preview never reads the flag. The
 * ninth group is the same case for a half-built rule row (`R-551873af`): the "Add condition" seed is a
 * `required_if` with no operator and no compared question, the server saves it, and the engine's lowering
 * throws on it at mount. This suite is the only thing between a keystroke and a poisoned preview.
 */

import { describe as group, expect, it } from 'vitest';

import {
    DRAFT_KEY_PREFIX,
    UNTITLED_LABEL,
    projectDraft,
    shapeOf,
    type DraftProjectionInput,
} from './draft-snapshot';
import type { BuilderValidation, LocalField, LocalSection } from './types';

import { createFormRuntime } from '../../../public-runtime/composables/useFormRuntime';

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

    it('4b. does not call a question with choices from another form empty, and still flags a typed one (M133)', () => {
        const linked = projectDraft(
            input([field({ uid: 'u1', field_type: 'dropdown', config: { options: [], options_source: { form_id: 'f-2', field_key: 'name' } } })]),
        );
        expect(linked.issues.map((i) => i.code)).not.toContain('empty_option_list');

        // A link REMOVED is a null key, and the typed list is empty again — that is the defect, and it is flagged.
        const unlinked = projectDraft(input([field({ uid: 'u1', field_type: 'dropdown', config: { options: [], options_source: null } })]));
        expect(unlinked.issues.map((i) => i.code)).toContain('empty_option_list');
    });

    it('4c. does not call a cascade whose every level names a CSV list empty, and still flags one that names some (M141)', () => {
        const levels = [{ key: 'region', label: 'Region', list: 'regions' }, { key: 'province', label: 'Province', list: 'provinces' }];
        const listed = projectDraft(input([field({ uid: 'u1', field_type: 'cascading_select', config: { levels, options: [] } })]));
        expect(listed.issues.map((i) => i.code)).not.toContain('empty_option_list');

        // Publishing refuses a cascade that names lists on some levels only, so the preview still calls it empty.
        const partial = projectDraft(input([field({ uid: 'u1', field_type: 'cascading_select', config: { levels: [levels[0], { key: 'province', label: 'Province' }], options: [] } })]));
        expect(partial.issues.map((i) => i.code)).toContain('empty_option_list');
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

    it('numbers each group by first appearance, as the server snapshot does, so the preview folds it (M131)', () => {
        const base = { operator: null, rule_value: '1', expression: null, error_message: null, related_field_key: null };
        const validations = [
            { ...base, rule_type: 'min_length', sequence: 0, logic_group: 'new-constraint', logic_operator: 'or' as const },
            { ...base, rule_type: 'max_length', sequence: 1, logic_group: 'new-constraint', logic_operator: 'or' as const },
            { ...base, rule_type: 'pattern', sequence: 2, logic_group: 'uuid-b', logic_operator: 'and' as const },
            { ...base, rule_type: 'pattern', sequence: 3 },
        ];
        const { schema } = projectDraft(input([field({ uid: 'u1', validations })]));
        const projected = schema.version.schema.fields[0].validations;

        expect(projected.map((v) => [v.logic_group_ordinal, v.logic_operator])).toEqual([
            [0, 'or'],
            [0, 'or'],
            [1, 'and'],
            [null, null],
        ]);

        // A regroup changes what the engine folds, so the shape key must move with it.
        const ungrouped = projectDraft(input([field({ uid: 'u1', validations: validations.map((v) => ({ ...v, logic_group: null, logic_operator: null })) })]));
        expect(shapeOf(ungrouped.schema.version.schema)).not.toBe(shapeOf(schema.version.schema));
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
                    // M145: every rule-row shape the engine throws on — the seed, a stale compared question, a
                    // half-typed expression, a row with neither half, and a grouped row with no connective.
                    field({
                        uid: 'd',
                        key: 'd',
                        sequence: 3,
                        validations: [
                            { rule_type: 'required_if', operator: null, rule_value: null, expression: null, error_message: null, related_field_key: null, sequence: 0 },
                            { rule_type: 'greater_than_field', operator: null, rule_value: null, expression: null, error_message: null, related_field_key: 'gone', sequence: 1 },
                            { rule_type: null, operator: null, rule_value: null, expression: '((((', error_message: null, related_field_key: null, sequence: 2 },
                            { rule_type: null, operator: null, rule_value: null, expression: null, error_message: null, related_field_key: null, sequence: 3 },
                            { rule_type: 'min_length', operator: null, rule_value: '1', expression: null, error_message: null, related_field_key: null, sequence: 4, logic_group: 'g', logic_operator: 'and' },
                            { rule_type: 'max_length', operator: null, rule_value: '9', expression: null, error_message: null, related_field_key: null, sequence: 5, logic_group: 'g', logic_operator: null },
                        ],
                    }),
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

group('9. rule rows — screened through the engine’s own lowering (M145, R-551873af)', () => {
    // ⛔ THE SAME LOAD-BEARING SHAPE AS CASE 5, ONE TABLE OVER. A rule row the lowering throws on — the
    // "Add condition" seed is one, and the server SAVES it — degrades the whole preview to everything-
    // relevant, and the preview never says so. The screen must run the ENGINE'S OWN lowering and parser,
    // never a list of rule names: `required_with` with no operator is a legitimate "is answered" condition.
    const rule = (overrides: Partial<BuilderValidation>): BuilderValidation => ({
        rule_type: 'required_if',
        operator: null,
        rule_value: null,
        expression: null,
        error_message: null,
        related_field_key: null,
        sequence: 0,
        ...overrides,
    });
    const earlier = field({ uid: 'e', key: 'earlier', sequence: 0 });
    const trigger = (validations: BuilderValidation[]): LocalField => field({ uid: 't', key: 'trigger', sequence: 1, validations });
    const later = field({ uid: 'l', key: 'later', sequence: 2 });
    const rulesOf = (schema: ReturnType<typeof projectDraft>['schema']) => schema.version.schema.fields[1].validations;

    it('9. omits the Add-condition seed — a required_if with no operator and no compared question — and records it', () => {
        const { schema, issues } = projectDraft(input([earlier, trigger([rule({})]), later]));

        expect(rulesOf(schema)).toHaveLength(0);
        expect(issues.map((i) => [i.uid, i.code])).toEqual([['t', 'incomplete_rule']]);
        expect(issues[0].message).toContain('not finished');
    });

    it('9b. keeps a complete required_if that names an EARLIER question, with no issue', () => {
        const { schema, issues } = projectDraft(
            input([earlier, trigger([rule({ operator: 'eq', rule_value: 'yes', related_field_key: 'earlier' })]), later]),
        );

        expect(rulesOf(schema)).toHaveLength(1);
        expect(rulesOf(schema)[0].related_field_key).toBe('earlier');
        expect(issues).toHaveLength(0);
    });

    it('9c. keeps a rule that names a LATER question, because every key is assigned before the screen runs', () => {
        const { schema, issues } = projectDraft(
            input([earlier, trigger([rule({ operator: 'eq', rule_value: 'yes', related_field_key: 'later' })]), later]),
        );

        expect(rulesOf(schema)).toHaveLength(1);
        expect(issues).toHaveLength(0);
    });

    it('9d. omits a rule whose compared question no longer exists, and says which kind of gap it is', () => {
        const { schema, issues } = projectDraft(
            input([earlier, trigger([rule({ operator: 'eq', rule_value: 'yes', related_field_key: 'gone' })]), later]),
        );

        expect(rulesOf(schema)).toHaveLength(0);
        expect(issues.map((i) => i.code)).toEqual(['incomplete_rule']);
        expect(issues[0].message).toContain('no longer exists');
    });

    it('9e. omits a greater_than_field with no compared question', () => {
        const { schema, issues } = projectDraft(input([earlier, trigger([rule({ rule_type: 'greater_than_field' })]), later]));

        expect(rulesOf(schema)).toHaveLength(0);
        expect(issues.map((i) => i.code)).toEqual(['incomplete_rule']);
    });

    it('9f. omits a half-typed free-text rule rather than nulling it, and keeps one that parses', () => {
        const { schema, issues } = projectDraft(
            input([
                earlier,
                trigger([
                    rule({ rule_type: null, expression: '${earlier} >' }),
                    rule({ rule_type: null, expression: "${earlier} > '1'", sequence: 1 }),
                ]),
                later,
            ]),
        );

        // A row with BOTH halves null throws `unlowerable_rule_type`, so nulling the expression is the wrong fix.
        expect(rulesOf(schema).map((v) => v.expression)).toEqual(["${earlier} > '1'"]);
        expect(issues.map((i) => i.code)).toEqual(['incomplete_rule']);
    });

    it('9g. keeps a required_with with no operator — the engine lowers it to "is answered"', () => {
        const { schema, issues } = projectDraft(
            input([earlier, trigger([rule({ rule_type: 'required_with', related_field_key: 'earlier' })]), later]),
        );

        expect(rulesOf(schema)).toHaveLength(1);
        expect(issues).toHaveLength(0);
    });

    it('9h. omits a grouped row with no connective and keeps the first', () => {
        const complete = { operator: 'eq', rule_value: 'a', related_field_key: 'earlier', logic_group: 'g' };
        const { schema, issues } = projectDraft(
            input([
                earlier,
                trigger([
                    rule({ ...complete, logic_operator: 'or', sequence: 0 }),
                    rule({ ...complete, rule_value: 'b', logic_operator: null, sequence: 1 }),
                ]),
                later,
            ]),
        );

        expect(rulesOf(schema).map((v) => v.rule_value)).toEqual(['a']);
        expect(issues.map((i) => i.code)).toEqual(['incomplete_rule']);
    });

    it('9i. an omitted row leaves the shape where a draft with no rule has it, so finishing the row rebuilds the preview', () => {
        const none = projectDraft(input([earlier, trigger([]), later])).shape;
        const seeded = projectDraft(input([earlier, trigger([rule({})]), later])).shape;
        const complete = projectDraft(input([earlier, trigger([rule({ operator: 'eq', rule_value: 'yes', related_field_key: 'earlier' })]), later])).shape;

        expect(seeded).toBe(none);
        expect(complete).not.toBe(none);
    });

    it('9j. the real engine neither fails nor turns everything relevant', () => {
        // The integration proof: the seed on `trigger`, a sibling hidden until `trigger` says show. Before the
        // screen, the lowering throws at mount, `safeEvaluate()` returns everything-relevant and the sibling shows.
        const dependent = field({ uid: 'd', key: 'dependent', sequence: 2, relevant_expression: "${trigger} = 'show'" });
        const { schema } = projectDraft(input([earlier, trigger([rule({})]), dependent]));

        const runtime = createFormRuntime(schema);

        expect(runtime.fieldRelevance.value.dependent).toBe(false);
        expect(runtime.engineFailed.value).toBe(false);
    });

    it('9k. records one issue per field however many of its rules are broken', () => {
        // `PreviewRuntime.vue` keys a field’s issue list by `issue.code`, so a second chip of one code would collide.
        const { issues } = projectDraft(
            input([earlier, trigger([rule({}), rule({ rule_type: 'greater_than_field', sequence: 1 })]), later]),
        );

        expect(issues).toHaveLength(1);
    });
});
