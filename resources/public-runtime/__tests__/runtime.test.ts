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
