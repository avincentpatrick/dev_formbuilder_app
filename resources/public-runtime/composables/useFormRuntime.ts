/**
 * The public-runtime store (Increment F6b; repeat groups added in G2) — the single source of truth for one
 * fill session. It owns the answer set, validation/relevance state (derived entirely from the F6a engine — the
 * SPA NEVER re-implements any evaluation), the touched/attempted gates for the hybrid validation UX, and the
 * step model for both the single-page and multi-step flows.
 *
 * Deliberately DOM-free and announcer-free so it is pure and unit-testable: components perform the focus and
 * aria-live side effects off the state and the pure results this store returns (e.g. `attemptNext()`).
 *
 * Retain-and-restore (UX §4.1) is automatic: `answers` keeps a hidden field's value (it is never deleted on
 * hide), the engine prunes it out of `effectiveAnswers`/`errors` while irrelevant, and it reappears intact if
 * relevance returns. The submit body is `effectiveAnswers` (relevance-pruned, XLSForm semantics).
 *
 * Repeat groups (Increment G2): a repeatable section's answers live under the SECTION key as a list of
 * per-instance field-key→value maps — the exact nested shape the G1 pipeline persists and the engine consumes.
 * Each instance carries a stable client `uid` (decoupled from its array index) so removing a middle row never
 * mis-addresses another row's touched/error state. Per-instance relevance/required/constraint/min/max come
 * straight from the engine's `repeatFieldRelevance` mask + instance-addressed errors — the store only manages
 * the answer-array shape and the per-instance hybrid-validation gates; it evaluates nothing itself.
 */

import { computed, reactive, ref, watch, type ComputedRef, type Ref } from 'vue';
import {
    makeSemanticValidator,
    SemanticResult,
    type CompositeAnswer,
    type EngineValue,
    type InstanceAnswers,
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
import type { AnswerMap, RenderField, RenderModel, RenderSection, SchemaResponse } from '../lib/types';

const LEAD_STEP_KEY = '__lead__';

export type Marker = 'required' | 'optional' | 'none';

export interface RuntimeStep {
    key: string;
    sectionKey: string | null;
    title: string | null;
    fieldKeys: string[];
    /** A repeatable-section step renders an add/remove-instance loop instead of a flat field list (G2). */
    isRepeat: boolean;
}

/** One entry in the error summary banner — addressed so instance + count errors jump correctly (G2). */
export interface ErroredItem {
    /** `field` | `section` | `section[i].field` — the anchor id suffix + the 422-envelope key. */
    address: string;
    label: string;
    /** The visible step this error belongs to, so the multi-step banner can scope to the current step. */
    stepKey: string;
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
    readonly answers: AnswerMap;
    readonly locale: Ref<string>;
    readonly engineFailed: Ref<boolean>;

    readonly result: ComputedRef<SemanticResult>;
    readonly fieldRelevance: ComputedRef<Record<string, boolean>>;
    readonly sectionRelevance: ComputedRef<Record<string, boolean>>;
    readonly effectiveAnswers: ComputedRef<AnswerMap>;
    readonly passed: ComputedRef<boolean>;
    readonly failingFields: ComputedRef<RenderField[]>;
    /** Relevant flat fields whose error is currently displayable (gated engine errors + server errors). */
    readonly erroredFields: ComputedRef<RenderField[]>;
    /** Every displayable error (flat + per-instance + count) as an addressed banner item, in document order. */
    readonly erroredItems: ComputedRef<ErroredItem[]>;

    readonly visibleSteps: ComputedRef<RuntimeStep[]>;
    readonly currentStepKey: Ref<string>;
    readonly currentStepIndex: ComputedRef<number>;
    readonly currentStep: ComputedRef<RuntimeStep | null>;
    readonly isFirstStep: ComputedRef<boolean>;
    readonly isLastStep: ComputedRef<boolean>;

    setAnswer(key: string, value: EngineValue | CompositeAnswer | null): void;
    restoreAnswers(map: AnswerMap): void;
    markTouched(key: string): void;
    markManyTouched(keys: string[]): void;
    markSubmitAttempted(): void;
    setServerErrors(fieldErrors: Record<string, string[]>): void;
    clearServerErrors(): void;

    isTouched(key: string): boolean;
    requiredMarkerFor(field: RenderField): Marker;
    rawErrorsFor(key: string): SemanticError[];
    errorFor(key: string): string | undefined;
    labelFor(field: RenderField): string;

    // ── Repeat groups (G2) ──────────────────────────────────────────────────────────────────────
    membersOf(sectionKey: string): RenderField[];
    minInstances(sectionKey: string): number;
    maxInstances(sectionKey: string): number | null;
    instanceCount(sectionKey: string): number;
    instanceUidsFor(sectionKey: string): string[];
    canAddInstance(sectionKey: string): boolean;
    addInstance(sectionKey: string): number;
    removeInstance(sectionKey: string, index: number): void;
    instanceAddress(sectionKey: string, index: number, fieldKey: string): string;
    instanceValue(sectionKey: string, index: number, fieldKey: string): EngineValue | null;
    setInstanceAnswer(sectionKey: string, index: number, fieldKey: string, value: EngineValue | null): void;
    instanceFieldRelevant(sectionKey: string, index: number, fieldKey: string): boolean;
    markInstanceTouched(sectionKey: string, index: number, fieldKey: string): void;
    instanceErrorFor(sectionKey: string, index: number, fieldKey: string): string | undefined;
    instanceRequiredMarkerFor(sectionKey: string, index: number, field: RenderField): Marker;
    sectionCountError(sectionKey: string): string | undefined;

    attemptNext(): AttemptResult;
    goPrev(): void;
    goToStep(key: string): void;
    reconcileCurrentStep(): void;
}

export interface RuntimeOptions {
    initialLocale?: string;
    /** Restored answers (draft autosave or version-drift carry-over); applied only for keys still in the schema. */
    initialAnswers?: AnswerMap;
}

function isInstanceObject(value: unknown): value is InstanceAnswers {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function createFormRuntime(schema: SchemaResponse, opts: RuntimeOptions = {}): FormRuntime {
    const renderModel = buildRenderModel(schema);
    const engineSchema: EngineSchema = buildEngineSchema(schema);
    const validator = makeSemanticValidator();

    // Repeatable-section metadata precomputed from the render model.
    const repeatSectionKeys = new Set(renderModel.sections.filter((s) => s.isRepeatable).map((s) => s.key));
    const repeatMeta: Record<string, { min: number; max: number | null }> = {};
    const membersBySection: Record<string, RenderField[]> = {};
    for (const section of renderModel.sections) {
        if (!section.isRepeatable) {
            continue;
        }
        repeatMeta[section.key] = { min: section.minInstances ?? 0, max: section.maxInstances ?? null };
        membersBySection[section.key] = renderModel.fields
            .filter((f) => f.sectionKey === section.key)
            .sort((a, b) => (a.sectionSequence ?? a.sequence) - (b.sectionSequence ?? b.sequence));
    }
    // Flat (non-repeat-member) field keys — the set a restored answer may target directly.
    const flatFieldKeys = new Set(
        renderModel.fields
            .filter((f) => f.sectionKey === null || !repeatSectionKeys.has(f.sectionKey))
            .map((f) => f.key),
    );

    // ── State ────────────────────────────────────────────────────────────────────────────────
    const answers = reactive<AnswerMap>({});
    // Parallel per-section stable identity, kept in lockstep with `answers[sectionKey]` across add/remove so a
    // spliced middle row cannot mis-address another row's touched/error state.
    const instanceUids = reactive<Record<string, string[]>>({});
    const serverErrors = reactive<Record<string, string[]>>({}); // keyed by ADDRESS (path), not just field key
    const touched = reactive<Record<string, true>>({});
    const instanceTouched = reactive<Record<string, true>>({});
    const sectionAttempted = reactive<Record<string, true>>({});
    const submitAttempted = ref(false);
    const engineFailed = ref(false);
    const locale = ref(opts.initialLocale ?? schema.form.default_locale);
    const clientSubmissionUuid = randomUuid();

    restoreAnswers(opts.initialAnswers ?? {});

    // Snapshot-degrade wrapper: a malformed published expression THROWS (it is pre-validated by the F3 gate,
    // so reaching here is a schema bug). Degrade to "everything relevant, no client errors" and let the
    // server stay authoritative at submit; flag it once for the UI.
    function safeEvaluate(): SemanticResult {
        try {
            return validator.evaluate(toSemanticInput(engineSchema, snapshotAnswers(), locale.value));
        } catch {
            engineFailed.value = true;
            const fieldRelevance = Object.fromEntries(engineSchema.fields.map((f) => [f.key, true]));
            const sectionRelevance = Object.fromEntries(engineSchema.sections.map((s) => [s.key, true]));
            return new SemanticResult(fieldRelevance, sectionRelevance, [], snapshotAnswers(), {}, {});
        }
    }

    /**
     * A per-level clone of the answer map that also READS every nested instance value, so the `result` computed
     * takes a reactive dependency on deep instance edits (a top-level shallow spread would miss them). The engine
     * treats its input as read-only, so a shallow-per-instance copy is enough.
     */
    function snapshotAnswers(): AnswerMap {
        const out: AnswerMap = {};
        for (const [key, value] of Object.entries(answers)) {
            if (repeatSectionKeys.has(key) && Array.isArray(value)) {
                // A repeatable-section key holds an instance array — shallow-clone each instance so `result`
                // takes a reactive dependency on deep instance edits.
                out[key] = (value as InstanceAnswers[]).map((instance) => ({ ...instance }));
            } else if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
                // A composite grid object (Increment G4b: matrix/likert_matrix). Deep-read + clone each row so
                // the `result` computed depends on nested cell edits and the engine gets an independent copy
                // it can freely prune. (A flat multi-select `string[]` is an Array — handled by the else.)
                const clone: Record<string, EngineValue | Record<string, EngineValue>> = {};
                for (const [rowKey, rowVal] of Object.entries(value as Record<string, unknown>)) {
                    clone[rowKey] =
                        rowVal !== null && typeof rowVal === 'object' && !Array.isArray(rowVal)
                            ? { ...(rowVal as Record<string, EngineValue>) }
                            : (rowVal as EngineValue);
                }
                out[key] = clone as CompositeAnswer;
            } else {
                // A scalar or a flat multi-select `string[]` — copied by reference (the engine never mutates it).
                out[key] = value;
            }
        }
        return out;
    }

    const result = computed(safeEvaluate);
    const fieldRelevance = computed(() => result.value.fieldRelevance);
    const sectionRelevance = computed(() => result.value.sectionRelevance);
    const effectiveAnswers = computed(() => result.value.effectiveAnswers as AnswerMap);
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
            steps.push({
                key: LEAD_STEP_KEY,
                sectionKey: null,
                title: null,
                fieldKeys: leadFields.map((f) => f.key),
                isRepeat: false,
            });
        }

        for (const section of renderModel.sections) {
            if (sectionRelevance.value[section.key] === false) {
                continue;
            }
            const isRepeat = section.isRepeatable;
            const sectionFields = isRepeat
                ? membersBySection[section.key] ?? []
                : renderModel.fields
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
                isRepeat,
            });
        }

        return steps;
    });

    const currentStepKey = ref<string>('');
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

    let lastValidIndex = 0;
    watch(currentStepIndex, (index) => {
        if (index >= 0) {
            lastValidIndex = index;
        }
    });

    function reconcileCurrentStep(): void {
        if (visibleSteps.value.length === 0) {
            return;
        }
        if (!visibleSteps.value.some((s) => s.key === currentStepKey.value)) {
            const target = Math.min(lastValidIndex, visibleSteps.value.length - 1);
            currentStepKey.value = visibleSteps.value[Math.max(target, 0)].key;
        }
    }

    watch(
        () => visibleSteps.value.some((s) => s.key === currentStepKey.value),
        (exists) => {
            if (!exists) {
                reconcileCurrentStep();
            }
        },
    );

    // ── Flat interaction ───────────────────────────────────────────────────────────────────────
    function setAnswer(key: string, value: EngineValue | CompositeAnswer | null): void {
        if (value === null) {
            delete answers[key];
        } else {
            answers[key] = value;
        }
        if (serverErrors[key] !== undefined) {
            delete serverErrors[key];
        }
    }

    /** Apply a restored answer map (draft/version-drift): flat keys straight in; repeat-section keys nested. */
    function restoreAnswers(map: AnswerMap): void {
        for (const [key, value] of Object.entries(map)) {
            if (value === null || value === undefined) {
                continue;
            }
            if (repeatSectionKeys.has(key)) {
                if (!Array.isArray(value)) {
                    continue;
                }
                const instances = value.filter(isInstanceObject);
                if (instances.length > 0) {
                    answers[key] = instances.map((instance) => ({ ...instance }));
                    instanceUids[key] = instances.map(() => randomUuid());
                }
                continue;
            }
            if (flatFieldKeys.has(key)) {
                answers[key] = value;
            }
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

    const erroredFields = computed(() =>
        renderModel.fields.filter(
            (f) => (f.sectionKey === null || !repeatSectionKeys.has(f.sectionKey)) && errorFor(f.key) !== undefined,
        ),
    );

    function requiredMarkerFor(field: RenderField): Marker {
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

    // ── Repeat groups (G2) ───────────────────────────────────────────────────────────────────────
    function membersOf(sectionKey: string): RenderField[] {
        return membersBySection[sectionKey] ?? [];
    }
    function minInstances(sectionKey: string): number {
        return repeatMeta[sectionKey]?.min ?? 0;
    }
    function maxInstances(sectionKey: string): number | null {
        return repeatMeta[sectionKey]?.max ?? null;
    }
    /** The instance array under a repeatable-section key, typed (a repeat key never holds a flat value). */
    function instanceArray(sectionKey: string): InstanceAnswers[] | null {
        const arr = answers[sectionKey];
        return Array.isArray(arr) ? (arr as InstanceAnswers[]) : null;
    }
    function instanceCount(sectionKey: string): number {
        return instanceArray(sectionKey)?.length ?? 0;
    }
    function instanceUidsFor(sectionKey: string): string[] {
        return instanceUids[sectionKey] ?? [];
    }
    function canAddInstance(sectionKey: string): boolean {
        const max = maxInstances(sectionKey);
        return max === null || instanceCount(sectionKey) < max;
    }
    function addInstance(sectionKey: string): number {
        if (!canAddInstance(sectionKey)) {
            return -1;
        }
        if (!Array.isArray(answers[sectionKey])) {
            answers[sectionKey] = [];
        }
        if (!Array.isArray(instanceUids[sectionKey])) {
            instanceUids[sectionKey] = [];
        }
        const arr = answers[sectionKey] as InstanceAnswers[];
        arr.push({});
        instanceUids[sectionKey].push(randomUuid());
        clearSectionServerErrors(sectionKey);
        return arr.length - 1;
    }
    function removeInstance(sectionKey: string, index: number): void {
        const arr = instanceArray(sectionKey);
        if (arr === null || index < 0 || index >= arr.length) {
            return;
        }
        arr.splice(index, 1);
        instanceUids[sectionKey]?.splice(index, 1);
        clearSectionServerErrors(sectionKey);
        if (arr.length === 0) {
            delete answers[sectionKey];
            delete instanceUids[sectionKey];
        }
    }
    function instanceAddress(sectionKey: string, index: number, fieldKey: string): string {
        return `${sectionKey}[${index}].${fieldKey}`;
    }
    function instanceValue(sectionKey: string, index: number, fieldKey: string): EngineValue | null {
        const arr = instanceArray(sectionKey);
        if (arr === null) {
            return null;
        }
        const value = arr[index]?.[fieldKey];
        return value === undefined ? null : value;
    }
    function setInstanceAnswer(sectionKey: string, index: number, fieldKey: string, value: EngineValue | null): void {
        const arr = instanceArray(sectionKey);
        if (arr === null) {
            return;
        }
        const instance = arr[index];
        if (instance === undefined) {
            return;
        }
        if (value === null) {
            delete instance[fieldKey];
        } else {
            instance[fieldKey] = value;
        }
        const address = instanceAddress(sectionKey, index, fieldKey);
        if (serverErrors[address] !== undefined) {
            delete serverErrors[address];
        }
    }
    function instanceFieldRelevant(sectionKey: string, index: number, fieldKey: string): boolean {
        return result.value.repeatFieldRelevance[sectionKey]?.[index]?.[fieldKey] === true;
    }
    function instanceTouchKey(sectionKey: string, uid: string, fieldKey: string): string {
        return `${sectionKey} ${uid} ${fieldKey}`;
    }
    function markInstanceTouched(sectionKey: string, index: number, fieldKey: string): void {
        const uid = instanceUids[sectionKey]?.[index];
        if (uid !== undefined) {
            instanceTouched[instanceTouchKey(sectionKey, uid, fieldKey)] = true;
        }
    }
    function isInstanceTouched(sectionKey: string, index: number, fieldKey: string): boolean {
        const uid = instanceUids[sectionKey]?.[index];
        return uid !== undefined && instanceTouched[instanceTouchKey(sectionKey, uid, fieldKey)] === true;
    }
    function instanceRawErrors(sectionKey: string, index: number, fieldKey: string): SemanticError[] {
        return result.value.errors.filter(
            (e) =>
                e.fieldKey === fieldKey &&
                (e.sectionKey ?? null) === sectionKey &&
                (e.instanceIndex ?? null) === index,
        );
    }
    function instanceErrorFor(sectionKey: string, index: number, fieldKey: string): string | undefined {
        const server = serverErrors[instanceAddress(sectionKey, index, fieldKey)];
        if (server !== undefined && server.length > 0) {
            return server[0];
        }
        if (!instanceFieldRelevant(sectionKey, index, fieldKey)) {
            return undefined;
        }
        if (isInstanceTouched(sectionKey, index, fieldKey) || submitAttempted.value) {
            const errs = instanceRawErrors(sectionKey, index, fieldKey);
            return errs.length > 0 ? errs[0].message : undefined;
        }
        return undefined;
    }
    function instanceRequiredMarkerFor(sectionKey: string, index: number, field: RenderField): Marker {
        const mode: RequiredMode = field.isRequired;
        if (mode === 'required') {
            return 'required';
        }
        if (mode === 'optional') {
            return 'optional';
        }
        const nowRequired = instanceRawErrors(sectionKey, index, field.key).some((e) => e.rule === 'field_required');
        return nowRequired ? 'required' : 'none';
    }
    function sectionCountError(sectionKey: string): string | undefined {
        const server = serverErrors[sectionKey];
        if (server !== undefined && server.length > 0) {
            return server[0];
        }
        if (sectionAttempted[sectionKey] !== true && !submitAttempted.value) {
            return undefined;
        }
        const err = result.value.errors.find(
            (e) => (e.sectionKey ?? null) === sectionKey && (e.instanceIndex ?? null) === null,
        );
        return err?.message;
    }
    function clearSectionServerErrors(sectionKey: string): void {
        for (const key of Object.keys(serverErrors)) {
            if (key === sectionKey || key.startsWith(`${sectionKey}[`)) {
                delete serverErrors[key];
            }
        }
    }
    function markSectionAttempted(sectionKey: string): void {
        sectionAttempted[sectionKey] = true;
    }
    function touchAllInstances(sectionKey: string): void {
        const arr = instanceArray(sectionKey);
        if (arr === null) {
            return;
        }
        const members = membersOf(sectionKey);
        arr.forEach((_, index) => {
            for (const field of members) {
                markInstanceTouched(sectionKey, index, field.key);
            }
        });
    }

    const erroredItems = computed<ErroredItem[]>(() => {
        const items: ErroredItem[] = [];
        for (const step of visibleSteps.value) {
            if (step.isRepeat && step.sectionKey !== null) {
                const sectionKey = step.sectionKey;
                const section = renderModel.sections.find((s) => s.key === sectionKey);
                const sectionLabel = section ? sectionTitle(section) : sectionKey;
                if (sectionCountError(sectionKey) !== undefined) {
                    items.push({ address: sectionKey, label: sectionLabel, stepKey: sectionKey });
                }
                const count = instanceCount(sectionKey);
                for (let index = 0; index < count; index++) {
                    for (const field of membersOf(sectionKey)) {
                        if (instanceErrorFor(sectionKey, index, field.key) !== undefined) {
                            items.push({
                                address: instanceAddress(sectionKey, index, field.key),
                                label: `${sectionLabel} ${index + 1}: ${labelFor(field)}`,
                                stepKey: sectionKey,
                            });
                        }
                    }
                }
                continue;
            }
            for (const key of step.fieldKeys) {
                const field = renderModel.fields.find((f) => f.key === key);
                if (field !== undefined && errorFor(key) !== undefined) {
                    items.push({ address: key, label: labelFor(field), stepKey: step.key });
                }
            }
        }
        return items;
    });

    function sectionTitle(section: RenderSection): string {
        return resolveText(section.label, section.labelTranslations, locale.value);
    }

    // ── Navigation ───────────────────────────────────────────────────────────────────────────────
    function attemptNext(): AttemptResult {
        const step = currentStep.value;
        if (step === null) {
            return { advanced: false, errorCount: 0 };
        }

        if (step.isRepeat && step.sectionKey !== null) {
            const sectionKey = step.sectionKey;
            const sectionErrors = result.value.errors.filter((e) => (e.sectionKey ?? null) === sectionKey);
            if (sectionErrors.length > 0) {
                markSectionAttempted(sectionKey);
                touchAllInstances(sectionKey);
                return { advanced: false, errorCount: sectionErrors.length };
            }
        } else {
            const relevantKeys = step.fieldKeys.filter((k) => fieldRelevance.value[k] === true);
            const errorKeys = relevantKeys.filter((k) => result.value.errorsFor(k).length > 0);
            if (errorKeys.length > 0) {
                markManyTouched(relevantKeys);
                return { advanced: false, errorCount: errorKeys.length };
            }
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
        erroredItems,
        visibleSteps,
        currentStepKey,
        currentStepIndex,
        currentStep,
        isFirstStep,
        isLastStep,
        setAnswer,
        restoreAnswers,
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
        membersOf,
        minInstances,
        maxInstances,
        instanceCount,
        instanceUidsFor,
        canAddInstance,
        addInstance,
        removeInstance,
        instanceAddress,
        instanceValue,
        setInstanceAnswer,
        instanceFieldRelevant,
        markInstanceTouched,
        instanceErrorFor,
        instanceRequiredMarkerFor,
        sectionCountError,
        attemptNext,
        goPrev,
        goToStep,
        reconcileCurrentStep,
    };
}
