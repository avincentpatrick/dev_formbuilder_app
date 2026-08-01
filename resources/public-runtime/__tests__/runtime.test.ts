import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse, section, validation } from './fixtures';

describe('createFormRuntime — relevance + retain-and-restore', () => {
    function conditionalSchema() {
        return schemaResponse({
            fields: [
                field({ key: 'trigger', sequence: 0 }),
                field({ key: 'detail', sequence: 1, relevant_expression: "${trigger} = 'yes'" }),
            ],
        });
    }

    it('hides an irrelevant field, retains its value, and restores it when relevance returns', () => {
        const runtime = createFormRuntime(conditionalSchema());

        runtime.setAnswer('trigger', 'yes');
        expect(runtime.fieldRelevance.value.detail).toBe(true);

        runtime.setAnswer('detail', 'kept text');
        // Turn it irrelevant — value is retained in the store but pruned from the submit set.
        runtime.setAnswer('trigger', 'no');
        expect(runtime.fieldRelevance.value.detail).toBe(false);
        expect(runtime.answers.detail).toBe('kept text');
        expect(runtime.effectiveAnswers.value.detail).toBeUndefined();

        // Relevance returns — the previously entered value is still there (never re-typed).
        runtime.setAnswer('trigger', 'yes');
        expect(runtime.fieldRelevance.value.detail).toBe(true);
        expect(runtime.answers.detail).toBe('kept text');
        expect(runtime.effectiveAnswers.value.detail).toBe('kept text');
    });
});

describe('createFormRuntime — hybrid validation timing', () => {
    function requiredSchema() {
        return schemaResponse({ fields: [field({ key: 'name', is_required: 'required', sequence: 0 })] });
    }

    it('does not surface a required error until the field is touched, then shows it live', () => {
        const runtime = createFormRuntime(requiredSchema());

        // Neutral on first render (untouched, no submit attempt).
        expect(runtime.errorFor('name')).toBeUndefined();
        expect(runtime.passed.value).toBe(false); // engine still knows it's invalid...

        runtime.markTouched('name');
        expect(runtime.errorFor('name')).toBe('This field is required.');

        // On-change after first error updates live.
        runtime.setAnswer('name', 'Ada');
        expect(runtime.errorFor('name')).toBeUndefined();
        expect(runtime.passed.value).toBe(true);
    });

    it('reveals all errors after a submit attempt even for untouched fields', () => {
        const runtime = createFormRuntime(requiredSchema());
        expect(runtime.errorFor('name')).toBeUndefined();
        runtime.markSubmitAttempted();
        expect(runtime.errorFor('name')).toBe('This field is required.');
        expect(runtime.erroredFields.value.map((f) => f.key)).toEqual(['name']);
    });

    it('surfaces the localized error message after a language switch', () => {
        const schema = schemaResponse({
            form: { supported_locales: ['en', 'es'] },
            fields: [
                field({
                    key: 'age',
                    field_type: 'integer',
                    validations: [
                        validation({
                            rule_type: 'min_value',
                            rule_value: '18',
                            error_message: 'Must be at least 18.',
                            error_message_translations: { es: 'Debe ser 18 o más.' },
                        }),
                    ],
                }),
            ],
        });
        const runtime = createFormRuntime(schema);
        runtime.setAnswer('age', 10);
        runtime.markTouched('age');
        expect(runtime.errorFor('age')).toBe('Must be at least 18.');

        runtime.locale.value = 'es';
        expect(runtime.errorFor('age')).toBe('Debe ser 18 o más.');
        // The answer is untouched by the switch.
        expect(runtime.answers.age).toBe(10);
    });
});

describe('createFormRuntime — required markers', () => {
    it('maps required/optional/conditional to the visual marker', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'req', is_required: 'required' }),
                field({ key: 'opt', is_required: 'optional' }),
                field({
                    key: 'cond',
                    is_required: 'conditional',
                    validations: [
                        validation({
                            rule_type: 'required_if',
                            operator: 'eq',
                            related_field_key: 'req',
                            rule_value: 'yes',
                        }),
                    ],
                }),
            ],
        });
        const runtime = createFormRuntime(schema);
        const cond = runtime.renderModel.fields.find((f) => f.key === 'cond')!;

        expect(runtime.requiredMarkerFor(runtime.renderModel.fields.find((f) => f.key === 'req')!)).toBe('required');
        expect(runtime.requiredMarkerFor(runtime.renderModel.fields.find((f) => f.key === 'opt')!)).toBe('optional');
        // Condition not triggered → no marker.
        expect(runtime.requiredMarkerFor(cond)).toBe('none');
        // Trigger the condition and leave `cond` empty → the required marker appears.
        runtime.setAnswer('req', 'yes');
        expect(runtime.requiredMarkerFor(cond)).toBe('required');
    });
});

describe('createFormRuntime — steps (multi-step recount + gate)', () => {
    function multiStepSchema() {
        return schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'One', sequence: 0 }),
                section({ key: 's2', label: 'Two', sequence: 1, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's3', label: 'Three', sequence: 2 }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', sequence: 0 }),
                field({ key: 'blocker', section_key: 's1', is_required: 'required', sequence: 1 }),
                field({ key: 'two', section_key: 's2', sequence: 2 }),
                field({ key: 'three', section_key: 's3', sequence: 3 }),
            ],
        });
    }

    it('dynamically recounts steps as a section becomes (ir)relevant', () => {
        const runtime = createFormRuntime(multiStepSchema());
        // s2 hidden by default (gate != 'go') → only s1, s3 visible.
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's3']);
        runtime.setAnswer('gate', 'go');
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's2', 's3']);
    });

    it('blocks Next when the current step has errors and reveals them', () => {
        const runtime = createFormRuntime(multiStepSchema());
        // On s1; `blocker` is required + empty.
        const result = runtime.attemptNext();
        expect(result.advanced).toBe(false);
        expect(result.errorCount).toBe(1);
        expect(runtime.currentStepKey.value).toBe('s1');
        // The blocked-step fields are now touched so the error shows.
        expect(runtime.errorFor('blocker')).toBe('This field is required.');

        runtime.setAnswer('blocker', 'ok');
        expect(runtime.attemptNext().advanced).toBe(true);
        expect(runtime.currentStepKey.value).toBe('s3'); // s2 still hidden
    });

    it('auto-advances off a step that becomes irrelevant', async () => {
        const runtime = createFormRuntime(multiStepSchema());
        runtime.setAnswer('gate', 'go'); // reveal s2
        runtime.setAnswer('blocker', 'ok');
        runtime.goToStep('s2');
        await nextTick(); // let the last-valid-index watcher record position s2
        expect(runtime.currentStepKey.value).toBe('s2');
        // Hide s2 again → the store's reconcile watcher should advance off it (async).
        runtime.setAnswer('gate', 'stop');
        await nextTick();
        expect(runtime.currentStepKey.value).not.toBe('s2');
        expect(runtime.visibleSteps.value.some((s) => s.key === runtime.currentStepKey.value)).toBe(true);
    });
});

describe('createFormRuntime — restored initial answers', () => {
    it('applies restored answers only for keys still in the schema', () => {
        const schema = schemaResponse({ fields: [field({ key: 'keep' })] });
        const runtime = createFormRuntime(schema, {
            initialAnswers: { keep: 'value', gone: 'orphan' },
        });
        expect(runtime.answers.keep).toBe('value');
        expect(runtime.answers.gone).toBeUndefined();
    });
});

// ── Repeat groups (Increment G2) ──────────────────────────────────────────────────────────────
function repeatSchema(over: { min?: number | null; max?: number | null; singlePage?: boolean } = {}) {
    return schemaResponse({
        form: { single_page_mode: over.singlePage ?? true },
        sections: [
            section({
                key: 'hh',
                label: 'Household members',
                sequence: 0,
                is_repeatable: true,
                min_instances: over.min === undefined ? 1 : over.min,
                max_instances: over.max === undefined ? 3 : over.max,
            }),
        ],
        fields: [
            field({ key: 'member_name', section_key: 'hh', is_required: 'required', sequence: 0, section_sequence: 0 }),
            field({
                key: 'member_age',
                section_key: 'hh',
                field_type: 'integer',
                sequence: 1,
                section_sequence: 1,
                validations: [validation({ rule_type: 'min_value', rule_value: '0', error_message: 'Age must be ≥ 0.' })],
            }),
        ],
    });
}

describe('createFormRuntime — repeat groups: add/remove + bounds', () => {
    it('adds and removes instances, honouring max', () => {
        const rt = createFormRuntime(repeatSchema()); // min 1, max 3
        expect(rt.instanceCount('hh')).toBe(0);

        expect(rt.addInstance('hh')).toBe(0);
        rt.addInstance('hh');
        rt.addInstance('hh');
        expect(rt.instanceCount('hh')).toBe(3);
        expect(rt.canAddInstance('hh')).toBe(false);
        expect(rt.addInstance('hh')).toBe(-1); // blocked at max

        rt.removeInstance('hh', 1);
        expect(rt.instanceCount('hh')).toBe(2);
        expect(rt.canAddInstance('hh')).toBe(true);
    });

    it('sets/gets per-instance values and shifts them correctly on removal', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh');
        rt.addInstance('hh');
        rt.setInstanceAnswer('hh', 0, 'member_name', 'A');
        rt.setInstanceAnswer('hh', 1, 'member_name', 'B');

        rt.removeInstance('hh', 0);
        expect(rt.instanceValue('hh', 0, 'member_name')).toBe('B');
        expect(rt.instanceCount('hh')).toBe(1);
    });

    it('keeps touched state attached to the instance (by uid) across a middle removal', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh');
        rt.addInstance('hh');
        // Touch row 0's required name (still empty) → its error shows; untouched row 1 stays quiet.
        rt.markInstanceTouched('hh', 0, 'member_name');
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBe('This field is required.');
        expect(rt.instanceErrorFor('hh', 1, 'member_name')).toBeUndefined();

        // Remove the touched row 0; old row 1 slides into index 0 and must remain untouched (uid-keyed).
        rt.removeInstance('hh', 0);
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBeUndefined();
    });
});

describe('createFormRuntime — repeat groups: per-instance validation + min/max', () => {
    it('surfaces a per-instance required error only once touched, then clears on entry', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh');
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBeUndefined();

        rt.markInstanceTouched('hh', 0, 'member_name');
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBe('This field is required.');

        rt.setInstanceAnswer('hh', 0, 'member_name', 'Bob');
        expect(rt.instanceValue('hh', 0, 'member_name')).toBe('Bob');
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBeUndefined();
    });

    it('evaluates a per-instance constraint independently per row', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh');
        rt.addInstance('hh');
        rt.setInstanceAnswer('hh', 0, 'member_age', -5);
        rt.setInstanceAnswer('hh', 1, 'member_age', 12);
        rt.markSubmitAttempted();

        expect(rt.instanceErrorFor('hh', 0, 'member_age')).toBe('Age must be ≥ 0.');
        expect(rt.instanceErrorFor('hh', 1, 'member_age')).toBeUndefined();
    });

    it('reports below-min and above-max as a gated section count error', () => {
        const rt = createFormRuntime(repeatSchema()); // min 1, max 3
        expect(rt.passed.value).toBe(false); // 0 < min
        expect(rt.sectionCountError('hh')).toBeUndefined(); // not attempted yet
        rt.markSubmitAttempted();
        expect(rt.sectionCountError('hh')).toBe('Provide at least 1 entry.');

        rt.addInstance('hh');
        rt.setInstanceAnswer('hh', 0, 'member_name', 'Bob');
        expect(rt.sectionCountError('hh')).toBeUndefined();
        expect(rt.passed.value).toBe(true);
    });

    it('reports above-max when restored past the ceiling', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.restoreAnswers({
            hh: [{ member_name: 'a' }, { member_name: 'b' }, { member_name: 'c' }, { member_name: 'd' }],
        });
        expect(rt.instanceCount('hh')).toBe(4);
        rt.markSubmitAttempted();
        expect(rt.sectionCountError('hh')).toBe('Provide at most 3 entries.');
    });
});

describe('createFormRuntime — repeat groups: per-instance relevance', () => {
    function relevanceSchema() {
        return schemaResponse({
            sections: [section({ key: 'hh', label: 'Members', is_repeatable: true, min_instances: 0, max_instances: 4 })],
            fields: [
                field({ key: 'is_dependent', section_key: 'hh', field_type: 'yes_no', sequence: 0, section_sequence: 0 }),
                field({
                    key: 'guardian',
                    section_key: 'hh',
                    is_required: 'required',
                    relevant_expression: "${is_dependent} = 'yes'",
                    sequence: 1,
                    section_sequence: 1,
                }),
            ],
        });
    }

    it('scopes relevance to the instance and retains a hidden value', () => {
        const rt = createFormRuntime(relevanceSchema());
        rt.addInstance('hh');
        expect(rt.instanceFieldRelevant('hh', 0, 'guardian')).toBe(false);

        rt.setInstanceAnswer('hh', 0, 'is_dependent', 'yes');
        expect(rt.instanceFieldRelevant('hh', 0, 'guardian')).toBe(true);
        rt.markSubmitAttempted();
        expect(rt.instanceErrorFor('hh', 0, 'guardian')).toBe('This field is required.');

        rt.setInstanceAnswer('hh', 0, 'guardian', 'Grandma');
        rt.setInstanceAnswer('hh', 0, 'is_dependent', 'no');
        expect(rt.instanceFieldRelevant('hh', 0, 'guardian')).toBe(false);
        expect(rt.instanceErrorFor('hh', 0, 'guardian')).toBeUndefined(); // hidden → not required
        expect(rt.instanceValue('hh', 0, 'guardian')).toBe('Grandma'); // retained
    });

    it('evaluates instance relevance independently across rows', () => {
        const rt = createFormRuntime(relevanceSchema());
        rt.addInstance('hh');
        rt.addInstance('hh');
        rt.setInstanceAnswer('hh', 0, 'is_dependent', 'yes');
        rt.setInstanceAnswer('hh', 1, 'is_dependent', 'no');

        expect(rt.instanceFieldRelevant('hh', 0, 'guardian')).toBe(true);
        expect(rt.instanceFieldRelevant('hh', 1, 'guardian')).toBe(false);
    });
});

describe('createFormRuntime — repeat groups: submit body + banner + steps', () => {
    it('builds a nested effectiveAnswers submit body', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh');
        rt.setInstanceAnswer('hh', 0, 'member_name', 'Bob');
        rt.setInstanceAnswer('hh', 0, 'member_age', 40);
        expect(rt.effectiveAnswers.value).toEqual({ hh: [{ member_name: 'Bob', member_age: 40 }] });
    });

    it('addresses per-instance errors in the banner items', () => {
        const rt = createFormRuntime(repeatSchema());
        rt.addInstance('hh'); // empty required name
        rt.setInstanceAnswer('hh', 0, 'member_name', ''); // keep row present but blank
        rt.markSubmitAttempted();
        const addresses = rt.erroredItems.value.map((i) => i.address);
        expect(addresses).toContain('hh[0].member_name');
    });

    it('restores nested instances with fresh uids', () => {
        const rt = createFormRuntime(repeatSchema(), {
            initialAnswers: { hh: [{ member_name: 'X' }, { member_name: 'Y' }], bogus: 'z' },
        });
        expect(rt.instanceCount('hh')).toBe(2);
        expect(rt.instanceValue('hh', 0, 'member_name')).toBe('X');
        expect(rt.instanceUidsFor('hh')).toHaveLength(2);
        expect(rt.answers.bogus).toBeUndefined();
    });

    it('blocks a multi-step repeat step until its instances are valid', () => {
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 'lead', label: 'Intro', sequence: 0 }),
                section({ key: 'hh', label: 'Members', sequence: 1, is_repeatable: true, min_instances: 1, max_instances: 3 }),
            ],
            fields: [
                field({ key: 'ref', section_key: 'lead', sequence: 0, section_sequence: 0 }),
                field({ key: 'member_name', section_key: 'hh', is_required: 'required', sequence: 1, section_sequence: 0 }),
            ],
        });
        const rt = createFormRuntime(schema);
        expect(rt.currentStepKey.value).toBe('lead');
        expect(rt.attemptNext().advanced).toBe(true); // lead field optional
        expect(rt.currentStepKey.value).toBe('hh');

        // 0 instances < min 1 → blocked with a count error.
        const blocked = rt.attemptNext();
        expect(blocked.advanced).toBe(false);
        expect(rt.sectionCountError('hh')).toBe('Provide at least 1 entry.');

        // Add an instance but leave the required name empty → still blocked, error revealed.
        rt.addInstance('hh');
        expect(rt.attemptNext().advanced).toBe(false);
        expect(rt.instanceErrorFor('hh', 0, 'member_name')).toBe('This field is required.');

        rt.setInstanceAnswer('hh', 0, 'member_name', 'Bob');
        expect(rt.attemptNext().advanced).toBe(true);
    });
});

describe('createFormRuntime — H10 resume seed (client_submission_uuid)', () => {
    it('mints a fresh uuid per session by default', () => {
        const a = createFormRuntime(schemaResponse({ fields: [field({ key: 'x', sequence: 0 })] }));
        const b = createFormRuntime(schemaResponse({ fields: [field({ key: 'x', sequence: 0 })] }));
        expect(a.clientSubmissionUuid).not.toBe(b.clientSubmissionUuid);
        expect(a.clientSubmissionUuid).toMatch(/^[0-9a-f-]{36}$/i);
    });

    it('reuses a seeded uuid so a resumed session promotes that same draft row', () => {
        const runtime = createFormRuntime(schemaResponse({ fields: [field({ key: 'x', sequence: 0 })] }), {
            initialClientSubmissionUuid: 'seeded-uuid-1234',
        });
        expect(runtime.clientSubmissionUuid).toBe('seeded-uuid-1234');
    });
});

/**
 * Increment H21a — predicate 3 of Doc #27 §2.2: a step is emitted only when at least one of its fields is
 * CURRENTLY relevant, not merely of a type that renders something.
 *
 * Predicates 1 and 2 were already here. Predicate 2 consults `rendersNothing(fieldType)`, a STATIC type
 * test that never looks at `fieldRelevance`, so a relevant section whose every field is individually gated
 * off rendered a heading, a Next button and zero questions — the grayed-out-skipped step
 * `form-filling-ux-flow.md` §3.2 explicitly rejected, arriving through a different door. `attemptNext()`
 * then found `relevantKeys` empty and advanced, so the respondent clicked Next through a dead step.
 *
 * The behavioural PHP↔TS parity for the whole projection lives in `steps.test.ts` against a shared fixture;
 * this suite covers the store-level details that fixture cannot express (the reactive recount, and that
 * `fieldKeys` is deliberately NOT filtered).
 */
describe('createFormRuntime — step visibility predicate 3 (H21a)', () => {
    it('drops a section whose every field is currently gated off, and restores it reactively', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 's1', sequence: 1 }), section({ key: 's2', sequence: 2 })],
                fields: [
                    field({ key: 'gate', section_key: 's1', sequence: 1 }),
                    field({ key: 'b', section_key: 's2', sequence: 2, relevant_expression: "${gate} = 'yes'" }),
                    field({ key: 'c', section_key: 's2', sequence: 3, relevant_expression: "${gate} = 'yes'" }),
                ],
            }),
            { initialAnswers: { gate: 'no' } },
        );

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1']);

        // Step MEMBERSHIP is now answer-reactive — the honest cost §2.2 states rather than hides.
        runtime.answers.gate = 'yes';
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's2']);
    });

    it('keeps a section when a single field survives, so the predicate is an any and not an all', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 's1', sequence: 1 }), section({ key: 's2', sequence: 2 })],
                fields: [
                    field({ key: 'gate', section_key: 's1', sequence: 1 }),
                    field({ key: 'b', section_key: 's2', sequence: 2, relevant_expression: "${gate} = 'yes'" }),
                    field({ key: 'c', section_key: 's2', sequence: 3 }),
                ],
            }),
            { initialAnswers: { gate: 'no' } },
        );

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's2']);
    });

    it('does not filter fieldKeys, so the error summary still addresses the whole step', () => {
        // Deliberate: `attemptNext()` and `erroredItems` already gate on relevance themselves, and filtering
        // the key list here would silently change what a jump link addresses. Only the EMPTINESS test moved.
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 's1', sequence: 1 })],
                fields: [
                    field({ key: 'gate', section_key: 's1', sequence: 1 }),
                    field({ key: 'sometimes', section_key: 's1', sequence: 2, relevant_expression: "${gate} = 'yes'" }),
                ],
            }),
            { initialAnswers: { gate: 'no' } },
        );

        expect(runtime.visibleSteps.value[0].fieldKeys).toEqual(['gate', 'sometimes']);
    });

    it('applies predicate 3 to the synthetic lead block too', () => {
        // Lead fields are top-level, so the flat mask covers them and a fully-gated lead block is exactly the
        // dead step predicate 3 abolishes. Missing this is the easiest way to half-implement the predicate.
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 's1', sequence: 2 })],
                fields: [
                    field({ key: 'lead_only', sequence: 1, relevant_expression: "${gate} = 'yes'" }),
                    field({ key: 'gate', section_key: 's1', sequence: 2 }),
                ],
            }),
            { initialAnswers: { gate: 'no' } },
        );

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1']);
    });

    it('keeps a repeatable step with zero instances, because the step is what lets you add one', () => {
        // The rule that stops predicate 3 annihilating every repeatable step, and the same rule both engines
        // apply before enforcing `min_instances` (Doc #27 §4.3).
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 'hh', sequence: 1, is_repeatable: true, min_instances: 2 })],
                fields: [field({ key: 'member_name', section_key: 'hh', sequence: 1 })],
            }),
            { initialAnswers: {} },
        );

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['hh']);
    });

    it('reads the per-instance masks for a repeatable step, never the flat mask', () => {
        // A repeat member is ABSENT from `fieldRelevance` (it is not `false` there, it is missing), so a
        // naive `fieldRelevance[memberKey] === true` would hide every repeatable step that has instances.
        const build = (gate: string) =>
            createFormRuntime(
                schemaResponse({
                    form: { single_page_mode: false },
                    sections: [section({ key: 's1', sequence: 1 }), section({ key: 'hh', sequence: 2, is_repeatable: true })],
                    fields: [
                        field({ key: 'gate', section_key: 's1', sequence: 1 }),
                        field({ key: 'member_name', section_key: 'hh', sequence: 2, relevant_expression: "${gate} = 'yes'" }),
                    ],
                }),
                { initialAnswers: { gate, hh: [{ member_name: 'Ana' }] } },
            );

        expect(build('yes').visibleSteps.value.map((s) => s.key)).toEqual(['s1', 'hh']);
        expect(build('no').visibleSteps.value.map((s) => s.key)).toEqual(['s1']);
    });
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Increment H21b — non-linear execution (Doc #27 §3.1, §4.1, §4.2, §4.4, §5.3).
//
// All five shapes below were recorded by H1f and left live by construction. Each asserts the mechanism
// directly rather than "did not throw": §9's standing caution is that an expectation reachable by more than
// one path pins nothing, and every one of these has a trivially-passing impostor.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/** Every section gated on a hidden field, so nothing renders until it is set — the §4.1 router-form shape. */
function gatedSchema(sections: string[], gate = 'gate') {
    return schemaResponse({
        form: { single_page_mode: false },
        sections: sections.map((key, i) =>
            section({ key, label: key.toUpperCase(), sequence: i + 1, relevant_expression: `\${${gate}} = 'go'` }),
        ),
        fields: [
            field({ key: gate, sequence: 0, field_type: 'hidden' }),
            ...sections.map((key, i) => field({ key: `${key}_q`, section_key: key, sequence: i + 1 })),
        ],
    });
}

describe('createFormRuntime — the empty graph is a terminal state (H21b §4.1)', () => {
    it('reports zero steps as terminal, and NOT as being on the last step', () => {
        const runtime = createFormRuntime(gatedSchema(['s1']));

        expect(runtime.visibleSteps.value).toEqual([]);
        expect(runtime.currentStepIndex.value).toBe(-1);
        // The defect: `findIndex` over [] is -1 and `length - 1` is also -1, so the old `index === length - 1`
        // was TRUE. The view rendered Submit over an empty form, nothing was relevant so nothing was required,
        // `passed` was true, and one click created a real submission with an empty answer document.
        expect(runtime.isLastStep.value).toBe(false);
        expect(runtime.isTerminal.value).toBe(true);
    });

    it('is not terminal, and IS on the last step, once a single step exists (the control)', async () => {
        const runtime = createFormRuntime(gatedSchema(['s1']));
        runtime.setAnswer('gate', 'go');
        await nextTick();

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1']);
        expect(runtime.isTerminal.value).toBe(false);
        expect(runtime.isLastStep.value).toBe(true);
    });

    it('seeds currentStepKey when the first step appears, so Next is not dead forever', async () => {
        const runtime = createFormRuntime(gatedSchema(['s1']));
        // Empty at CONSTRUCTION, so the eager seed never ran.
        expect(runtime.currentStepKey.value).toBe('');

        runtime.setAnswer('gate', 'go');
        await nextTick();

        // The old membership-boolean watcher was false at t=0 and STAYED false when steps appeared, so it
        // never fired at all: the index stayed -1, `attemptNext()` failed its `idx >= 0` guard, and Next did
        // nothing on every click, permanently.
        expect(runtime.currentStepKey.value).toBe('s1');
        expect(runtime.currentStepIndex.value).toBe(0);
    });

    it('advances off the newly-seeded step instead of silently doing nothing', async () => {
        const runtime = createFormRuntime(gatedSchema(['s1', 's2']));
        runtime.setAnswer('gate', 'go');
        await nextTick();

        expect(runtime.attemptNext()).toEqual({ advanced: true, errorCount: 0 });
        expect(runtime.currentStepKey.value).toBe('s2');
    });
});

describe('createFormRuntime — rescue by identity, not by index (H21b §4.2)', () => {
    /** [s1 .. s4] where s1 always shows, `gate` controls s2/s3 and `tail` controls s4 independently. */
    function cascadeSchema() {
        return schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'One', sequence: 1 }),
                section({ key: 's2', label: 'Two', sequence: 2, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's3', label: 'Three', sequence: 3, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's4', label: 'Four', sequence: 4, relevant_expression: "${tail} = 'go'" }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', sequence: 1 }),
                field({ key: 'tail', section_key: 's1', sequence: 2 }),
                field({ key: 'two', section_key: 's2', sequence: 3 }),
                field({ key: 'three', section_key: 's3', sequence: 4 }),
                field({ key: 'four', section_key: 's4', sequence: 5 }),
            ],
        });
    }

    async function onStepFour() {
        const runtime = createFormRuntime(cascadeSchema());
        runtime.setAnswer('gate', 'go');
        runtime.setAnswer('tail', 'go');
        await nextTick();
        runtime.goToStep('s4');
        await nextTick();
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's2', 's3', 's4']);
        expect(runtime.currentStepKey.value).toBe('s4');
        return runtime;
    }

    it('lands on the nearest surviving PREDECESSOR when only the current step vanishes', async () => {
        const runtime = await onStepFour();

        runtime.setAnswer('tail', 'stop'); // s4 alone disappears
        await nextTick();

        expect(runtime.currentStepKey.value).toBe('s3');
    });

    it('walks past a whole vanished tail rather than clamping the index', async () => {
        const runtime = await onStepFour();

        // s2, s3 and s4 all hide at once. The old rule was `min(lastValidIndex, length - 1)` = `min(3, 0)` = 0.
        // Identity walks outward from s4 — past s3 and s2, both now gone — and lands on s1.
        runtime.setAnswer('gate', 'stop');
        runtime.setAnswer('tail', 'stop');
        await nextTick();

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1']);
        expect(runtime.currentStepKey.value).toBe('s1');
    });

    it('walks FORWARD when the vanished step had no surviving predecessor', async () => {
        // §4.2 says walk OUTWARD, and both directions are load-bearing: the respondent is on the FIRST step
        // when it removes itself, so there is nothing behind them to walk back to. Found by mutation —
        // deleting the forward arm reddened nothing until this test existed.
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [
                    section({ key: 's1', label: 'One', sequence: 1, relevant_expression: "${leave} != 'stop'" }),
                    section({ key: 's2', label: 'Two', sequence: 2 }),
                ],
                fields: [
                    field({ key: 'leave', section_key: 's1', sequence: 1 }),
                    field({ key: 'two', section_key: 's2', sequence: 2 }),
                ],
            }),
        );
        expect(runtime.currentStepKey.value).toBe('s1');

        runtime.setAnswer('leave', 'stop');
        await nextTick();

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s2']);
        expect(runtime.currentStepKey.value).toBe('s2');
        expect(runtime.lastStepChange.value?.rescuedFrom).toBe('s1');
        expect(runtime.lastStepChange.value?.rescuedTo).toBe('s2');
    });

    it('publishes the rescue on lastStepChange so a component can announce the REASON', async () => {
        const runtime = await onStepFour();

        runtime.setAnswer('tail', 'stop');
        await nextTick();

        const change = runtime.lastStepChange.value;
        expect(change?.rescuedFrom).toBe('s4');
        expect(change?.rescuedTo).toBe('s3');
        expect(change?.removed).toEqual(['s4']);
        expect(change?.previousCount).toBe(4);
        expect(change?.count).toBe(3);
    });

    it('leaves rescuedFrom null when the respondent’s own step survived (§3.1)', async () => {
        const runtime = createFormRuntime(cascadeSchema());
        runtime.setAnswer('gate', 'go');
        await nextTick();
        expect(runtime.currentStepKey.value).toBe('s1');

        // The count changes BEHIND the respondent; nobody is moved, but N went 3 → 1 in silence.
        runtime.setAnswer('gate', 'stop');
        await nextTick();

        expect(runtime.currentStepKey.value).toBe('s1');
        expect(runtime.lastStepChange.value?.rescuedFrom).toBeNull();
        expect(runtime.lastStepChange.value?.previousCount).toBe(3);
        expect(runtime.lastStepChange.value?.count).toBe(1);
    });

    it('reports a rescue with no destination when the graph empties under the respondent', async () => {
        const runtime = createFormRuntime(gatedSchema(['s1']));
        runtime.setAnswer('gate', 'go');
        await nextTick();
        expect(runtime.currentStepKey.value).toBe('s1');

        runtime.setAnswer('gate', 'stop');
        await nextTick();

        expect(runtime.isTerminal.value).toBe(true);
        expect(runtime.lastStepChange.value?.rescuedFrom).toBe('s1');
        expect(runtime.lastStepChange.value?.rescuedTo).toBeNull();
    });
});

describe('createFormRuntime — the notice a step can give and a field cannot (H21b §4.4)', () => {
    function notedSchema() {
        return schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'One', sequence: 1 }),
                section({ key: 's2', label: 'Two', sequence: 2, relevant_expression: "${gate} = 'go'" }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', sequence: 1 }),
                field({ key: 'two', section_key: 's2', sequence: 2 }),
            ],
        });
    }

    it('names a removed step that still holds an answer', async () => {
        const runtime = createFormRuntime(notedSchema());
        runtime.setAnswer('gate', 'go');
        await nextTick();
        runtime.setAnswer('two', 'typed something');

        runtime.setAnswer('gate', 'stop');
        await nextTick();

        expect(runtime.lastStepChange.value?.removed).toEqual(['s2']);
        expect(runtime.lastStepChange.value?.removedWithAnswers).toEqual(['s2']);
        // The data is safe; this is about SAYING so, which no `FieldRow` can do for an unmounted step.
        expect(runtime.answers.two).toBe('typed something');
    });

    it('stays silent about a removed step that holds nothing', async () => {
        const runtime = createFormRuntime(notedSchema());
        runtime.setAnswer('gate', 'go');
        await nextTick();

        runtime.setAnswer('gate', 'stop');
        await nextTick();

        expect(runtime.lastStepChange.value?.removed).toEqual(['s2']);
        expect(runtime.lastStepChange.value?.removedWithAnswers).toEqual([]);
    });
});

describe('createFormRuntime — the visited set (H21b §3.1)', () => {
    function reappearSchema() {
        return schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'One', sequence: 1 }),
                section({ key: 's2', label: 'Two', sequence: 2, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's3', label: 'Three', sequence: 3 }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', sequence: 1 }),
                field({ key: 'two', section_key: 's2', sequence: 2 }),
                field({ key: 'three', section_key: 's3', sequence: 3 }),
            ],
        });
    }

    it('does not mark a step the respondent was never on, even when it sits behind them', async () => {
        const runtime = createFormRuntime(reappearSchema());
        runtime.attemptNext(); // s1 → s3, because s2 is hidden
        expect(runtime.currentStepKey.value).toBe('s3');
        expect(runtime.hasVisited('s1')).toBe(true);
        expect(runtime.hasVisited('s3')).toBe(true);

        // s2 appears BEHIND the respondent. `index < currentIndex` would call it done from this moment on and
        // offer a clickable "go to step 2" dot for a step they have never seen.
        runtime.setAnswer('gate', 'go');
        await nextTick();
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['s1', 's2', 's3']);
        expect(runtime.hasVisited('s2')).toBe(false);
    });

    it('marks a step visited once the respondent actually navigates to it', async () => {
        const runtime = createFormRuntime(reappearSchema());
        runtime.setAnswer('gate', 'go');
        await nextTick();
        runtime.goToStep('s2');
        expect(runtime.hasVisited('s2')).toBe(true);
    });
});

describe('createFormRuntime — resume drift resolution (H21b §5.3)', () => {
    function resumeSchema() {
        return schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'One', sequence: 1 }),
                section({ key: 's2', label: 'Two', sequence: 2, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's3', label: 'Three', sequence: 3 }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', sequence: 1 }),
                field({ key: 'two', section_key: 's2', sequence: 2 }),
                field({ key: 'three', section_key: 's3', sequence: 3 }),
            ],
        });
    }

    it('reports an exact hit and adopts the key', () => {
        const runtime = createFormRuntime(resumeSchema());
        expect(runtime.goToStep('s3')).toBe('exact');
        expect(runtime.currentStepKey.value).toBe('s3');
    });

    it('walks a known-but-irrelevant key to its nearest surviving predecessor — the H21 case', () => {
        const runtime = createFormRuntime(resumeSchema());
        // s2 is authored but currently gated off: the respondent saved their cursor there and the branch then
        // moved. This used to be a guarded no-op, leaving them on step 1 with no explanation at all.
        expect(runtime.goToStep('s2')).toBe('nearest');
        expect(runtime.currentStepKey.value).toBe('s1');
    });

    it('resolves a stored FIRST step forward when it is the one that is irrelevant', () => {
        // The other half of §4.2's outward walk, on the resume path: nothing precedes the stored key.
        const runtime = createFormRuntime(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [
                    section({ key: 's1', label: 'One', sequence: 1, relevant_expression: "${gate} = 'go'" }),
                    section({ key: 's2', label: 'Two', sequence: 2 }),
                ],
                fields: [
                    field({ key: 'gate', sequence: 0, field_type: 'hidden' }),
                    field({ key: 'one', section_key: 's1', sequence: 1 }),
                    field({ key: 'two', section_key: 's2', sequence: 2 }),
                ],
            }),
        );
        expect(runtime.goToStep('s1')).toBe('nearest');
        expect(runtime.currentStepKey.value).toBe('s2');
    });

    it('falls back to the first INCOMPLETE step for a key this version no longer has', () => {
        const runtime = createFormRuntime(resumeSchema());
        runtime.setAnswer('gate', 'answered'); // s1 is now complete, so the fallback must skip past it
        expect(runtime.goToStep('s_removed_by_a_republish')).toBe('first-incomplete');
        expect(runtime.currentStepKey.value).toBe('s3');
    });

    it('reports `none` and adopts nothing when the graph is empty', () => {
        const runtime = createFormRuntime(gatedSchema(['s1']));
        expect(runtime.goToStep('s1')).toBe('none');
        expect(runtime.currentStepKey.value).toBe('');
    });
});
