import { computed } from 'vue';
import { describe, expect, it } from 'vitest';
import { createFormRuntime } from '../composables/useFormRuntime';
import { buildTemplateSources } from '../lib/schema-mapping';
import { field, schemaResponse, section } from './fixtures';

/**
 * The piping runtime (Increment H6b, Doc #26 §3/§4) — the reactive half H6a deliberately left unbuilt.
 *
 * The cross-engine FORMATTING contract lives in `tests/golden/templates/` and is asserted by
 * `engine/__tests__/golden-templates.test.ts` against the identical PHP fixtures. What this file covers is
 * everything a language-neutral vector cannot express: which answer document a hole reads, repeat scope,
 * locale reactivity, and the promise that a hole-free form pays nothing.
 *
 * EVERY `${` here is inside a single-quoted or template string that is never itself interpolated.
 */

describe('piping — which answer document a hole reads', () => {
    it('fills a hole from a preceding answer, and re-renders as it changes', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'child_name', sequence: 0 }),
                    field({ key: 'age', sequence: 1, label: 'Age of ${child_name}' }),
                ],
            }),
        );
        const age = runtime.renderModel.fields[1];

        expect(runtime.labelFor(age)).toBe('Age of ');

        runtime.setAnswer('child_name', 'Ana');
        expect(runtime.labelFor(age)).toBe('Age of Ana');

        runtime.setAnswer('child_name', 'Beni');
        expect(runtime.labelFor(age)).toBe('Age of Beni');
    });

    it('never emits the raw token, for an unanswered source or an unknown key', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'known', sequence: 0 }),
                    field({ key: 'q', sequence: 1, label: 'A ${known} B ${ghost} C' }),
                ],
            }),
        );

        // §3.4 — publish refuses an unresolvable REFERENCE; render tolerates an unanswered VALUE, as the
        // empty string. Never `${key}`, never `undefined`, never a placeholder glyph.
        expect(runtime.labelFor(runtime.renderModel.fields[1])).toBe('A  B  C');
        expect(runtime.labelFor(runtime.renderModel.fields[1])).not.toContain('${');
    });

    it('fills from a CALCULATED value, which lives outside effectiveAnswers', () => {
        // The regression test for this increment's easiest mistake. `SemanticResult.computed` is a separate
        // map that is never merged into `effectiveAnswers`; PHP persists the two merged, and that merged
        // document is what the inbox renders from. Reading `effectiveAnswers` alone renders this blank in
        // the SPA and correct in the inbox — silently killing the case §3.1 cites as the REASON
        // `calculated` is pipeable at all.
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'qty', field_type: 'integer', sequence: 0 }),
                    field({ key: 'price', field_type: 'integer', sequence: 1 }),
                    field({
                        key: 'total',
                        field_type: 'calculated',
                        sequence: 2,
                        config: { calculated_formula: '${qty} * ${price}' },
                    }),
                    field({ key: 'confirm', sequence: 3, label: 'Total is ${total}. Correct?' }),
                ],
            }),
        );

        runtime.setAnswer('qty', 3);
        runtime.setAnswer('price', 4);

        expect(runtime.labelFor(runtime.renderModel.fields[3])).toBe('Total is 12. Correct?');
    });

    it('renders a hole naming a currently-IRRELEVANT field as empty, while the answer is still retained', () => {
        // Pruned, not retained: a hidden field's value is kept but never submitted, so piping it would
        // promise the respondent an answer that will not be recorded.
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'trigger', sequence: 0 }),
                    field({ key: 'detail', sequence: 1, relevant_expression: "${trigger} = 'yes'" }),
                    field({ key: 'q', sequence: 2, label: 'About ${detail}' }),
                ],
            }),
        );
        const q = runtime.renderModel.fields[2];

        runtime.setAnswer('trigger', 'yes');
        runtime.setAnswer('detail', 'the roof');
        expect(runtime.labelFor(q)).toBe('About the roof');

        runtime.setAnswer('trigger', 'no');
        expect(runtime.labelFor(q)).toBe('About ');
        expect(runtime.answers.detail).toBe('the roof');   // retained, just not piped
    });
});

describe('piping — locale', () => {
    function localeSchema() {
        return schemaResponse({
            form: { supported_locales: ['en', 'fil'] },
            fields: [
                field({ key: 'child_name', sequence: 0 }),
                field({
                    key: 'age',
                    sequence: 1,
                    label: 'Age of ${child_name}',
                    label_translations: { fil: 'Edad ni ${child_name}' },
                }),
            ],
        });
    }

    it('resolves the locale variant FIRST, then fills its holes', () => {
        // §4's normative order. A render-then-resolve implementation produces either the English filled
        // text or the Filipino unfilled text; this asserts neither.
        const runtime = createFormRuntime(localeSchema());
        const age = runtime.renderModel.fields[1];

        runtime.setAnswer('child_name', 'Ana');
        expect(runtime.labelFor(age)).toBe('Age of Ana');

        runtime.locale.value = 'fil';
        expect(runtime.labelFor(age)).toBe('Edad ni Ana');
    });

    it('resolves a piped choice label into the same locale as the sentence around it', () => {
        // Amendment A8. Before H6b the sentence resolved and the value dropped into it did not, so a
        // Filipino respondent read an English option label mid-Filipino-sentence.
        const runtime = createFormRuntime(
            schemaResponse({
                form: { supported_locales: ['en', 'fil'] },
                fields: [
                    field({
                        key: 'region',
                        field_type: 'single_select',
                        sequence: 0,
                        config: {
                            options: [
                                { value: 'ncr', label: 'Metro Manila', label_translations: { fil: 'Kalakhang Maynila' } },
                            ],
                        },
                    }),
                    field({
                        key: 'q',
                        sequence: 1,
                        label: 'More about ${region}?',
                        label_translations: { fil: 'Tungkol sa ${region}?' },
                    }),
                ],
            }),
        );
        const q = runtime.renderModel.fields[1];

        runtime.setAnswer('region', 'ncr');
        expect(runtime.labelFor(q)).toBe('More about Metro Manila?');

        runtime.locale.value = 'fil';
        expect(runtime.labelFor(q)).toBe('Tungkol sa Kalakhang Maynila?');
    });
});

describe('piping — repeat scope (§3.3 rule 2)', () => {
    function rosterRuntime() {
        return createFormRuntime(
            schemaResponse({
                sections: [{ ...section({ key: 'roster', label: 'Member', sequence: 1 }), is_repeatable: true, min_instances: 0, max_instances: 5 }],
                fields: [
                    field({ key: 'household', sequence: 0 }),
                    field({ key: 'member_name', section_key: 'roster', sequence: 1 }),
                    field({ key: 'member_age', section_key: 'roster', sequence: 2, label: 'Age of ${member_name}' }),
                ],
            }),
        );
    }

    it('resolves a same-instance sibling per instance, not from instance 0 or the flat map', () => {
        const runtime = rosterRuntime();
        const memberAge = runtime.membersOf('roster')[1];

        runtime.addInstance('roster');
        runtime.addInstance('roster');
        runtime.setInstanceAnswer('roster', 0, 'member_name', 'Ana');
        runtime.setInstanceAnswer('roster', 1, 'member_name', 'Beni');

        expect(runtime.labelFor(memberAge, { sectionKey: 'roster', index: 0 })).toBe('Age of Ana');
        expect(runtime.labelFor(memberAge, { sectionKey: 'roster', index: 1 })).toBe('Age of Beni');
    });

    it('still resolves a FLAT hole from inside a repeat instance', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                sections: [{ ...section({ key: 'roster', label: 'Member', sequence: 1 }), is_repeatable: true, min_instances: 0, max_instances: 5 }],
                fields: [
                    field({ key: 'household', sequence: 0 }),
                    field({ key: 'member_note', section_key: 'roster', sequence: 1, label: 'In the ${household} household' }),
                ],
            }),
        );

        runtime.setAnswer('household', 'Reyes');
        runtime.addInstance('roster');

        expect(runtime.labelFor(runtime.membersOf('roster')[0], { sectionKey: 'roster', index: 0 })).toBe('In the Reyes household');
    });

    it('renders empty rather than throwing when the addressed instance is gone', () => {
        const runtime = rosterRuntime();
        const memberAge = runtime.membersOf('roster')[1];

        expect(runtime.labelFor(memberAge, { sectionKey: 'roster', index: 7 })).toBe('Age of ');
    });
});

describe('piping — the zero-cost promise for hole-free forms', () => {
    it('does not make a hole-free label depend on the answer document', () => {
        // The load-bearing property of the `includes("${")` guard: every form authored before H6b keeps
        // exactly the reactive graph it had. If this regresses, one keystroke invalidates every label on
        // the page for no benefit.
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'a', sequence: 0 }),
                    field({ key: 'plain', sequence: 1, label: 'A plain label' }),
                    field({ key: 'piped', sequence: 2, label: 'Piped ${a}' }),
                ],
            }),
        );

        let plainRuns = 0;
        let pipedRuns = 0;
        const plain = computed(() => {
            plainRuns++;
            return runtime.labelFor(runtime.renderModel.fields[1]);
        });
        const piped = computed(() => {
            pipedRuns++;
            return runtime.labelFor(runtime.renderModel.fields[2]);
        });

        void plain.value;
        void piped.value;
        expect(plainRuns).toBe(1);
        expect(pipedRuns).toBe(1);

        runtime.setAnswer('a', 'x');
        void plain.value;
        void piped.value;

        expect(plainRuns).toBe(1);   // never re-evaluated: it took no dependency on the answers at all
        expect(pipedRuns).toBe(2);
    });
});

describe('piping — the nullable columns and the source map', () => {
    it('keeps a null hint null rather than collapsing it to an empty string', () => {
        const runtime = createFormRuntime(schemaResponse({ fields: [field({ key: 'a', sequence: 0 })] }));

        expect(runtime.hintFor(runtime.renderModel.fields[0])).toBeNull();
        expect(runtime.placeholderFor(runtime.renderModel.fields[0])).toBeNull();
    });

    it('pipes a hint and a placeholder, which are template-bearing per §6', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    field({ key: 'name', sequence: 0 }),
                    field({ key: 'q', sequence: 1, hint: 'As known to ${name}', placeholder: 'e.g. ${name}' }),
                ],
            }),
        );

        runtime.setAnswer('name', 'Ana');
        expect(runtime.hintFor(runtime.renderModel.fields[1])).toBe('As known to Ana');
        // Placeholder was passed through RAW before H6b, so a published hole reached the screen verbatim.
        expect(runtime.placeholderFor(runtime.renderModel.fields[1])).toBe('e.g. Ana');
    });

    it('builds sources from the RAW config, over every field including hidden and calculated', () => {
        // Not from the render model: `toRenderField()` has already projected `config` away, and
        // `buildOptions()` skips `cascading_select` entirely — so a renderer fed from there emits raw codes.
        const schema = schemaResponse({
            fields: [
                field({ key: 'shown', sequence: 0 }),
                field({ key: 'secret', field_type: 'hidden', sequence: 1 }),
                field({ key: 'total', field_type: 'calculated', sequence: 2 }),
                field({
                    key: 'place',
                    field_type: 'cascading_select',
                    sequence: 3,
                    config: { options: [{ value: 'ncr', label: 'Metro Manila', level: 'region', parent: null }] },
                }),
            ],
        });

        const sources = buildTemplateSources(schema);

        expect(Object.keys(sources).sort()).toEqual(['place', 'secret', 'shown', 'total']);
        expect(sources.secret.type).toBe('hidden');
        expect(sources.place.config).toEqual({
            options: [{ value: 'ncr', label: 'Metro Manila', level: 'region', parent: null }],
        });
    });
});
