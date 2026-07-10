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
