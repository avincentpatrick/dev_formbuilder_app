/**
 * The public-runtime store (Increment F6b) — the single source of truth for one fill session. It owns the
 * answer set, validation/relevance state (derived entirely from the F6a engine — the SPA NEVER re-implements
 * any evaluation), the touched/attempted gates for the hybrid validation UX, and the step model for both the
 * single-page and multi-step flows.
 *
 * Deliberately DOM-free and announcer-free so it is pure and unit-testable: components perform the focus and
 * aria-live side effects off the state and the pure results this store returns (e.g. `attemptNext()`).
 *
 * Retain-and-restore (UX §4.1) is automatic: `answers` keeps a hidden field's value (it is never deleted on
 * hide), the engine prunes it out of `effectiveAnswers`/`errors` while irrelevant, and it reappears intact if
 * relevance returns. The submit body is `effectiveAnswers` (relevance-pruned, XLSForm semantics).
 */

import { computed, reactive, ref, watch, type ComputedRef, type Ref } from 'vue';
import {
    makeSemanticValidator,
    SemanticResult,
    type EngineValue,
    type RequiredMode,
    type SemanticError,
} from '../engine';
import {
    buildEngineSchema,
    buildRenderModel,
    resolveText,
    toSemanticInput,
    type EngineSchema,
} from '../lib/schema-mapping';
import { randomUuid } from '../lib/uuid';
import type { RenderField, RenderModel, SchemaResponse } from '../lib/types';

const LEAD_STEP_KEY = '__lead__';

export interface RuntimeStep {
    key: string;
    sectionKey: string | null;
    title: string | null;
    fieldKeys: string[];
}

export interface AttemptResult {
    advanced: boolean;
    errorCount: number;
}

export interface FormRuntime {
    readonly renderModel: RenderModel;
    readonly singlePageMode: boolean;
    readonly schemaChecksum: string;
    readonly clientSubmissionUuid: string;
    readonly answers: Record<string, EngineValue>;
    readonly locale: Ref<string>;
    readonly engineFailed: Ref<boolean>;

    readonly result: ComputedRef<SemanticResult>;
    readonly fieldRelevance: ComputedRef<Record<string, boolean>>;
    readonly sectionRelevance: ComputedRef<Record<string, boolean>>;
    readonly effectiveAnswers: ComputedRef<Record<string, EngineValue>>;
    readonly passed: ComputedRef<boolean>;
    readonly failingFields: ComputedRef<RenderField[]>;
    /** Relevant fields whose error is currently displayable (gated engine errors + server errors) — banner set. */
    readonly erroredFields: ComputedRef<RenderField[]>;

    readonly visibleSteps: ComputedRef<RuntimeStep[]>;
    readonly currentStepKey: Ref<string>;
    readonly currentStepIndex: ComputedRef<number>;
    readonly currentStep: ComputedRef<RuntimeStep | null>;
    readonly isFirstStep: ComputedRef<boolean>;
    readonly isLastStep: ComputedRef<boolean>;

    setAnswer(key: string, value: EngineValue | null): void;
    markTouched(key: string): void;
    markManyTouched(keys: string[]): void;
    markSubmitAttempted(): void;
    setServerErrors(fieldErrors: Record<string, string[]>): void;
    clearServerErrors(): void;

    isTouched(key: string): boolean;
    requiredMarkerFor(field: RenderField): 'required' | 'optional' | 'none';
    rawErrorsFor(key: string): SemanticError[];
    errorFor(key: string): string | undefined;
    labelFor(field: RenderField): string;

    attemptNext(): AttemptResult;
    goPrev(): void;
    goToStep(key: string): void;
    reconcileCurrentStep(): void;
}

export interface RuntimeOptions {
    initialLocale?: string;
    /** Restored answers (draft autosave or version-drift carry-over); applied only for keys still in the schema. */
    initialAnswers?: Record<string, EngineValue>;
}

export function createFormRuntime(schema: SchemaResponse, opts: RuntimeOptions = {}): FormRuntime {
    const renderModel = buildRenderModel(schema);
    const engineSchema: EngineSchema = buildEngineSchema(schema);
    const validator = makeSemanticValidator();

    const validKeys = new Set(renderModel.fields.map((f) => f.key));
    const answers = reactive<Record<string, EngineValue>>({});
    for (const [key, value] of Object.entries(opts.initialAnswers ?? {})) {
        if (validKeys.has(key) && value !== null) {
            answers[key] = value;
        }
    }
    const serverErrors = reactive<Record<string, string[]>>({});
    const touched = reactive<Record<string, true>>({});
    const submitAttempted = ref(false);
    const engineFailed = ref(false);
    const locale = ref(opts.initialLocale ?? schema.form.default_locale);
    const clientSubmissionUuid = randomUuid();

    // Snapshot-degrade wrapper: a malformed published expression THROWS (it is pre-validated by the F3 gate,
    // so reaching here is a schema bug). Degrade to "everything relevant, no client errors" and let the
    // server stay authoritative at submit; flag it once for the UI.
    function safeEvaluate(): SemanticResult {
        try {
            return validator.evaluate(toSemanticInput(engineSchema, { ...answers }, locale.value));
        } catch {
            engineFailed.value = true;
            const fieldRelevance = Object.fromEntries(engineSchema.fields.map((f) => [f.key, true]));
            const sectionRelevance = Object.fromEntries(engineSchema.sections.map((s) => [s.key, true]));
            return new SemanticResult(fieldRelevance, sectionRelevance, [], { ...answers }, {});
        }
    }

    const result = computed(safeEvaluate);
    const fieldRelevance = computed(() => result.value.fieldRelevance);
    const sectionRelevance = computed(() => result.value.sectionRelevance);
    // The engine's effectiveAnswers may hold repeat-group instance arrays (Increment G1), but F6b does not
    // render repeatable sections yet (that is G2), so this runtime only ever sees flat scalar answers here.
    const effectiveAnswers = computed(() => result.value.effectiveAnswers as Record<string, EngineValue>);
    const passed = computed(() => result.value.passed());

    const failingFields = computed(() =>
        renderModel.fields.filter(
            (f) => fieldRelevance.value[f.key] === true && result.value.errorsFor(f.key).length > 0,
        ),
    );

    // ── Steps ────────────────────────────────────────────────────────────────────────────────
    const visibleSteps = computed<RuntimeStep[]>(() => {
        const steps: RuntimeStep[] = [];

        const leadFields = renderModel.fields
            .filter((f) => f.sectionKey === null)
            .sort((a, b) => a.sequence - b.sequence);
        if (leadFields.length > 0) {
            steps.push({ key: LEAD_STEP_KEY, sectionKey: null, title: null, fieldKeys: leadFields.map((f) => f.key) });
        }

        for (const section of renderModel.sections) {
            if (sectionRelevance.value[section.key] === false) {
                continue;
            }
            const sectionFields = renderModel.fields
                .filter((f) => f.sectionKey === section.key)
                .sort((a, b) => (a.sectionSequence ?? a.sequence) - (b.sectionSequence ?? b.sequence));
            if (sectionFields.length === 0) {
                continue;
            }
            steps.push({
                key: section.key,
                sectionKey: section.key,
                title: resolveText(section.label, section.labelTranslations, locale.value),
                fieldKeys: sectionFields.map((f) => f.key),
            });
        }

        return steps;
    });

    const currentStepKey = ref<string>('');
    // Initialize to the first step.
    if (visibleSteps.value.length > 0) {
        currentStepKey.value = visibleSteps.value[0].key;
    }

    const currentStepIndex = computed(() => visibleSteps.value.findIndex((s) => s.key === currentStepKey.value));
    const currentStep = computed<RuntimeStep | null>(() => {
        const idx = currentStepIndex.value;
        if (idx >= 0) {
            return visibleSteps.value[idx];
        }
        return visibleSteps.value.length > 0 ? visibleSteps.value[0] : null;
    });
    const isFirstStep = computed(() => currentStepIndex.value <= 0);
    const isLastStep = computed(() => currentStepIndex.value === visibleSteps.value.length - 1);

    // Remember the last position the respondent was actually on, so if that step later becomes irrelevant we
    // can auto-advance to the step now occupying its slot (the "next relevant step", UX §4.1) rather than
    // snapping to the start.
    let lastValidIndex = 0;
    watch(currentStepIndex, (index) => {
        if (index >= 0) {
            lastValidIndex = index;
        }
    });

    /** If the current step became irrelevant (e.g. an earlier edit hid this section), snap to the nearest valid. */
    function reconcileCurrentStep(): void {
        if (visibleSteps.value.length === 0) {
            return;
        }
        if (!visibleSteps.value.some((s) => s.key === currentStepKey.value)) {
            const target = Math.min(lastValidIndex, visibleSteps.value.length - 1);
            currentStepKey.value = visibleSteps.value[Math.max(target, 0)].key;
        }
    }

    // Auto-advance off a step that just became irrelevant (fires only when the current key stops existing).
    watch(
        () => visibleSteps.value.some((s) => s.key === currentStepKey.value),
        (exists) => {
            if (!exists) {
                reconcileCurrentStep();
            }
        },
    );

    // ── Interaction ──────────────────────────────────────────────────────────────────────────
    function setAnswer(key: string, value: EngineValue | null): void {
        if (value === null) {
            delete answers[key];
        } else {
            answers[key] = value;
        }
        // A fresh edit invalidates any stale server-side error on that field.
        if (serverErrors[key] !== undefined) {
            delete serverErrors[key];
        }
    }

    function markTouched(key: string): void {
        touched[key] = true;
    }
    function markManyTouched(keys: string[]): void {
        for (const key of keys) {
            touched[key] = true;
        }
    }
    function isTouched(key: string): boolean {
        return touched[key] === true;
    }
    function markSubmitAttempted(): void {
        submitAttempted.value = true;
    }
    function setServerErrors(fieldErrors: Record<string, string[]>): void {
        clearServerErrors();
        for (const [key, messages] of Object.entries(fieldErrors)) {
            serverErrors[key] = messages;
        }
    }
    function clearServerErrors(): void {
        for (const key of Object.keys(serverErrors)) {
            delete serverErrors[key];
        }
    }

    function rawErrorsFor(key: string): SemanticError[] {
        return result.value.errorsFor(key);
    }

    function errorFor(key: string): string | undefined {
        const server = serverErrors[key];
        if (server !== undefined && server.length > 0) {
            return server[0];
        }
        if (fieldRelevance.value[key] !== true) {
            return undefined;
        }
        if (touched[key] === true || submitAttempted.value) {
            const errs = result.value.errorsFor(key);
            return errs.length > 0 ? errs[0].message : undefined;
        }
        return undefined;
    }

    function labelFor(field: RenderField): string {
        return resolveText(field.label, field.labelTranslations, locale.value);
    }

    const erroredFields = computed(() => renderModel.fields.filter((f) => errorFor(f.key) !== undefined));

    /**
     * The visual required marker (UX §4.4). `conditional` shows the required marker only once the engine
     * actually treats the field as required-and-empty (a `field_required` error is present) — never for a
     * condition that has not triggered. A conditional field that is required but already filled shows no
     * marker (an accepted Phase-1 simplification — the marker matters most while empty).
     */
    function requiredMarkerFor(field: RenderField): 'required' | 'optional' | 'none' {
        const mode: RequiredMode = field.isRequired;
        if (mode === 'required') {
            return 'required';
        }
        if (mode === 'optional') {
            return 'optional';
        }
        const conditionallyRequiredNow = result.value.errorsFor(field.key).some((e) => e.rule === 'field_required');
        return conditionallyRequiredNow ? 'required' : 'none';
    }

    function attemptNext(): AttemptResult {
        const step = currentStep.value;
        if (step === null) {
            return { advanced: false, errorCount: 0 };
        }
        const relevantKeys = step.fieldKeys.filter((k) => fieldRelevance.value[k] === true);
        const errorKeys = relevantKeys.filter((k) => result.value.errorsFor(k).length > 0);
        if (errorKeys.length > 0) {
            markManyTouched(relevantKeys); // reveal the inline errors the respondent hasn't triggered yet
            return { advanced: false, errorCount: errorKeys.length };
        }
        const idx = currentStepIndex.value;
        if (idx >= 0 && idx < visibleSteps.value.length - 1) {
            currentStepKey.value = visibleSteps.value[idx + 1].key;
            return { advanced: true, errorCount: 0 };
        }
        return { advanced: true, errorCount: 0 };
    }

    function goPrev(): void {
        const idx = currentStepIndex.value;
        if (idx > 0) {
            currentStepKey.value = visibleSteps.value[idx - 1].key;
        }
    }

    function goToStep(key: string): void {
        if (visibleSteps.value.some((s) => s.key === key)) {
            currentStepKey.value = key;
        }
    }

    return {
        renderModel,
        singlePageMode: schema.form.single_page_mode,
        schemaChecksum: schema.version.checksum,
        clientSubmissionUuid,
        answers,
        locale,
        engineFailed,
        result,
        fieldRelevance,
        sectionRelevance,
        effectiveAnswers,
        passed,
        failingFields,
        erroredFields,
        visibleSteps,
        currentStepKey,
        currentStepIndex,
        currentStep,
        isFirstStep,
        isLastStep,
        setAnswer,
        markTouched,
        markManyTouched,
        markSubmitAttempted,
        setServerErrors,
        clearServerErrors,
        isTouched,
        requiredMarkerFor,
        rawErrorsFor,
        errorFor,
        labelFor,
        attemptNext,
        goPrev,
        goToStep,
        reconcileCurrentStep,
    };
}
