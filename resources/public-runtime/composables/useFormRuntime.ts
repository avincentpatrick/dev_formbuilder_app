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
    Coercion,
    makeSemanticValidator,
    makeTemplateRenderer,
    SemanticResult,
    type CompositeAnswer,
    type EngineValue,
    type GeoAnswer,
    type InstanceAnswers,
    type MediaAnswer,
    type RequiredMode,
    type SemanticError,
} from '../engine';
import {
    buildEngineSchema,
    buildRenderModel,
    buildTemplateSources,
    rendersNothing,
    resolveOptional,
    resolveText,
    toSemanticInput,
    type EngineSchema,
} from '../lib/schema-mapping';
import { buildPrefill } from '../lib/prefill';
import { randomUuid, uuidv7 } from '../lib/uuid';
import type { AnswerMap, RenderField, RenderModel, RenderSection, SchemaResponse } from '../lib/types';

/**
 * The synthetic leading step holding every renderable field with no section — the twin of PHP's
 * `StepProjection::LEAD_STEP_KEY`, and the one step that can never carry a `relevant_expression` of its own
 * because it has no `form_sections` row to hang one on.
 *
 * EXPORTED since H21c: the Inertia encode page mounts this same store and has to recognise the lead step to
 * decide where a keyer-only row belongs. Re-declaring the literal there would have made it a THIRD copy of a
 * string whose whole job is to be identical in two places.
 */
export const LEAD_STEP_KEY = '__lead__';

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

/**
 * Increment H21b — one record of what the step list just did, published by the store for components to act
 * on. Doc #27 §4.4 makes the shape a constraint rather than a preference: the store is DOM-free and
 * announcer-free, so the step-level equivalents of the field-level focus rescue, retain-and-restore note and
 * "new question" announcement must arrive as a VALUE the component reads — the shape `attemptNext()` already
 * uses — and never as an announcer call from in here.
 */
export interface StepChange {
    /** Newly visible step keys, in document order (§3.1 — a step can appear BEHIND the respondent). */
    added: string[];
    /** Step keys that are no longer visible. */
    removed: string[];
    /**
     * The subset of `removed` that still holds a respondent-entered answer (§4.4). The data is safe — answers
     * are never deleted on hide — but in multi-step mode no `FieldRow` for an off-screen step is mounted, so
     * the field-level note cannot fire and today NOTHING is said. This is what a component says it with.
     */
    removedWithAnswers: string[];
    /** The step the respondent was on when it vanished, or null if the current step survived (§4.2). */
    rescuedFrom: string | null;
    /** Where they were moved to; null when the graph emptied out entirely (§4.1's terminal state). */
    rescuedTo: string | null;
    previousCount: number;
    count: number;
}

/**
 * How `goToStep()` resolved a requested key (Increment H21b, Doc #27 §5.3). It used to be a silent no-op on
 * an unresolvable key, which is why all three resume-drift cases landed on step 1 with no explanation.
 */
export type StepResolution =
    /** The key was visible and was adopted as-is. */
    | 'exact'
    /** The key is known but not currently visible; resolved to the nearest surviving predecessor (§4.2). */
    | 'nearest'
    /** No surviving predecessor (or the key is unknown to this version); resolved to the first incomplete step. */
    | 'first-incomplete'
    /** There are no visible steps at all — the §4.1 terminal state; nothing was adopted. */
    | 'none';

/** Addresses one repeat instance for a scoped template render (Increment H6b, Doc #26 §3.3 rule 2). */
export interface RepeatScope {
    sectionKey: string;
    index: number;
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
    /** True with zero visible steps — Doc #27 §4.1's specified TERMINAL state, not an error (H21b). */
    readonly isTerminal: ComputedRef<boolean>;
    /** What the step list last did, for the component-side announcements and focus rescue (H21b, §3.1/§4.2/§4.4). */
    readonly lastStepChange: Ref<StepChange | null>;

    setAnswer(key: string, value: EngineValue | CompositeAnswer | GeoAnswer | MediaAnswer | null): void;
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

    // ── Piping (H6b, Doc #26) — locale-resolved THEN hole-filled, in that order ─────────────────
    // `scope` names the repeat instance a hole resolves against; omit it at flat scope.
    labelFor(field: RenderField, scope?: RepeatScope): string;
    hintFor(field: RenderField, scope?: RepeatScope): string | null;
    placeholderFor(field: RenderField, scope?: RepeatScope): string | null;
    sectionTitleFor(section: RenderSection): string;
    sectionDescriptionFor(section: RenderSection): string | null;
    templateText(base: string, translations: Record<string, string> | null, scope?: RepeatScope): string;
    templateOptional(base: string | null, translations: Record<string, string> | null, scope?: RepeatScope): string | null;

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
    goToStep(key: string): StepResolution;
    reconcileCurrentStep(): void;
    /**
     * Has the respondent actually BEEN on this step? Increment H21b — `ProgressIndicator` marks "done" from
     * this rather than from `index < currentIndex`, because under branching a step can become relevant BEHIND
     * the respondent (§3.1) and an index comparison hands it a clickable "done" dot it never earned.
     */
    hasVisited(key: string): boolean;
}

export interface RuntimeOptions {
    initialLocale?: string;
    /** Restored answers (draft autosave or version-drift carry-over); applied only for keys still in the schema. */
    initialAnswers?: AnswerMap;
    /**
     * Increment H10 — seed the session's `client_submission_uuid` from a resumed server draft instead of
     * minting a fresh one. This is the promote linchpin: the H9a substrate finalizes a same-uuid submit through
     * `promote()` (flipping the existing draft row) rather than `submit()` (a new row), so a resumed session
     * MUST reuse the draft's uuid or the finalize would create a duplicate submission.
     */
    initialClientSubmissionUuid?: string;
    /**
     * Increment H7 — the raw `location.search` this session was opened with, used to prefill `url`-sourced
     * hidden fields. Passed IN rather than read here so this store stays DOM-free and unit-testable (its own
     * docblock's invariant); `buildPrefill()` is a pure function of it.
     */
    search?: string;
    /**
     * Increment H21a — the ISO-8601 clock `today()` / `now()` read, in PHP's exact `toIso8601String()` shape
     * (build it with `isoClock()`; Doc #27 §3.4). Passed IN for the same reason `search` is: reading the
     * device clock here would break this store's pure, unit-testable invariant.
     *
     * FROZEN FOR THE SESSION, deliberately. A ticking clock would re-run `safeEvaluate()` on every tick and
     * invalidate `visibleSteps`, `erroredItems` and the autosave watcher with it. The narrowing is that a
     * session held open across midnight keeps its opening date; a resume re-mounts and re-stamps. The
     * server's clock stays authoritative at submit either way.
     */
    now?: string | null;
}

function isInstanceObject(value: unknown): value is InstanceAnswers {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function createFormRuntime(schema: SchemaResponse, opts: RuntimeOptions = {}): FormRuntime {
    const renderModel = buildRenderModel(schema);
    const engineSchema: EngineSchema = buildEngineSchema(schema);
    // Increment H6b — the piping source map. Built ONCE from the frozen snapshot, so it is a plain object
    // and deliberately never wrapped in `reactive()`: nothing about it can change during a session.
    const templateSources = buildTemplateSources(schema);
    const templateRenderer = makeTemplateRenderer();
    const validator = makeSemanticValidator();
    // Increment H21a — read once, never re-read, so the whole session evaluates `today()`/`now()` against one
    // clock (see `RuntimeOptions.now`). Undefined stays null, which is exactly the pre-H21a behaviour.
    const sessionNow = opts.now ?? null;

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
    // Increment G8b — a time-sortable UUIDv7 (was random v4): it is the outbox key + the server idempotency
    // key, and its leading timestamp gives queued submissions a coarse chronological order for replay.
    // Increment H10 — a resumed session reuses the server draft's uuid so its eventual submit PROMOTES that
    // draft row rather than creating a duplicate (the H9a same-uuid-finalize-through-promote invariant).
    const clientSubmissionUuid = opts.initialClientSubmissionUuid ?? uuidv7();

    // Increment H7 — hidden-field prefill, seeded FIRST so a restored answer always wins over it. That
    // ordering is the whole rule: a resumed draft carries no query string, and the value it saved is the one
    // the respondent's submission was built against. Routing both through `restoreAnswers` also means the
    // prefill inherits its flat-key guard for free.
    restoreAnswers(buildPrefill(schema, opts.search ?? ''));
    restoreAnswers(opts.initialAnswers ?? {});

    // Snapshot-degrade wrapper: a malformed published expression THROWS (it is pre-validated by the F3 gate,
    // so reaching here is a schema bug). Degrade to "everything relevant, no client errors" and let the
    // server stay authoritative at submit; flag it once for the UI.
    function safeEvaluate(): SemanticResult {
        try {
            return validator.evaluate(toSemanticInput(engineSchema, snapshotAnswers(), locale.value, sessionNow));
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
    /**
     * Predicate 3 of Doc #27 §2.2, new in H21a: a step is emitted only when at least one of its fields is
     * CURRENTLY relevant, not merely of a type that renders something.
     *
     * Predicates 1 and 2 are static about answers — `sectionRelevance` moves, but the emptiness test below
     * consults `rendersNothing(fieldType)`, a TYPE predicate that never looks at `fieldRelevance`. So a
     * relevant section whose every field is individually gated off renders a heading, a Next button and zero
     * questions: the grayed-out-skipped step `form-filling-ux-flow.md` §3.2 explicitly rejected, arriving
     * through a different door. `attemptNext()` then finds `relevantKeys` empty and advances, so the
     * respondent clicks Next through a dead step. Under branching this stops being an edge case — gating
     * FIELDS is the more natural authoring style than hoisting the condition onto the section.
     *
     * The mask to consult depends on the step, and getting this wrong annihilates every repeatable step:
     * `fieldRelevance` is dense over TOP-LEVEL fields only (the engine's `fullMask`), so a repeat MEMBER's
     * key is `undefined` there, not `false` — its relevance lives in `repeatFieldRelevance`, one mask per
     * instance. Hence the split below.
     *
     * ZERO INSTANCES IS VACUOUSLY VISIBLE. The step is what lets the respondent add the first instance, so
     * an empty group must keep its step — and the server agrees, because `repeatStepIsVisible()` in both
     * engines applies exactly this rule before enforcing `min_instances` (§4.3, amendment A4).
     *
     * The honest cost, stated rather than hidden: step MEMBERSHIP becomes answer-reactive, which is the same
     * coupling deliberately refused for step TITLES below. No new evaluation is introduced — `fieldRelevance`
     * and `repeatFieldRelevance` are already computeds off the same `SemanticResult` — only a new dependency
     * edge, reaching `currentStepIndex`, `erroredItems` and the autosave watcher.
     */
    function anyFieldCurrentlyRelevant(fields: RenderField[], sectionKey: string | null, isRepeat: boolean): boolean {
        if (isRepeat && sectionKey !== null) {
            const masks = result.value.repeatFieldRelevance[sectionKey] ?? [];
            if (masks.length === 0) {
                return true;
            }

            return masks.some((mask) => fields.some((f) => mask[f.key] === true));
        }

        return fields.some((f) => fieldRelevance.value[f.key] === true);
    }

    const visibleSteps = computed<RuntimeStep[]>(() => {
        const steps: RuntimeStep[] = [];

        const leadFields = renderModel.fields
            .filter((f) => f.sectionKey === null && !rendersNothing(f.fieldType))
            .sort((a, b) => a.sequence - b.sequence);
        // The lead block needs predicate 3 too — its fields are top-level, so `fieldRelevance` covers them,
        // and a fully-gated lead block is exactly the dead step this predicate abolishes.
        if (leadFields.length > 0 && anyFieldCurrentlyRelevant(leadFields, null, false)) {
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
            const sectionFields = (
                isRepeat
                    ? membersBySection[section.key] ?? []
                    : renderModel.fields
                          .filter((f) => f.sectionKey === section.key)
                          .sort((a, b) => (a.sectionSequence ?? a.sequence) - (b.sectionSequence ?? b.sequence))
            ).filter((f) => !rendersNothing(f.fieldType));
            // Increment H7 — the filter runs BEFORE this emptiness check on purpose: a section holding
            // nothing but hidden/calculated fields has no question in it, so it should vanish entirely
            // rather than render a heading over a blank panel.
            if (sectionFields.length === 0) {
                continue;
            }
            // Increment H21a — predicate 3. Deliberately NOT applied to `fieldKeys` below: filtering the key
            // list would change `erroredItems` and `attemptNext()`'s existing behaviour for no gain, since
            // both already gate on relevance themselves. Only the emptiness test moves.
            if (!anyFieldCurrentlyRelevant(sectionFields, section.key, isRepeat)) {
                continue;
            }
            steps.push({
                key: section.key,
                sectionKey: section.key,
                // Locale-resolved but NOT piped, deliberately (Increment H6b). `visibleSteps` feeds
                // `currentStepIndex`, `erroredItems` and the watcher that writes `currentStepKey`, so
                // rendering holes in here would put the whole answer document into the step model's
                // dependency graph and couple step NAVIGATION to typing, for a cosmetic gain. Consumers
                // pipe it at the point of display via `sectionTitleFor()`.
                title: resolveText(section.label, section.labelTranslations, locale.value),
                fieldKeys: sectionFields.map((f) => f.key),
                isRepeat,
            });
        }

        return steps;
    });

    const currentStepKey = ref<string>('');
    /** Steps the respondent has actually been ON — see `hasVisited()` on the interface for why not an index. */
    const visitedStepKeys = ref<ReadonlySet<string>>(new Set());
    const lastStepChange = ref<StepChange | null>(null);

    function hasVisited(key: string): boolean {
        return visitedStepKeys.value.has(key);
    }

    function setCurrentStep(key: string): void {
        currentStepKey.value = key;
        if (key !== '' && !visitedStepKeys.value.has(key)) {
            const next = new Set(visitedStepKeys.value);
            next.add(key);
            visitedStepKeys.value = next;
        }
    }

    if (visibleSteps.value.length > 0) {
        setCurrentStep(visibleSteps.value[0].key);
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
    // Increment H21b, Doc #27 §4.1 — the length guard is the whole fix. `findIndex` over an empty list is -1
    // and `length - 1` is also -1, so the old `index === length - 1` was TRUE on an empty graph: the view
    // rendered Submit over no questions, nothing was relevant so nothing was required, `passed` was true, and
    // one click created a real submission with an empty answer document under a false step count.
    const isLastStep = computed(
        () => visibleSteps.value.length > 0 && currentStepIndex.value === visibleSteps.value.length - 1,
    );
    const isTerminal = computed(() => visibleSteps.value.length === 0);

    /** The field-level retain-and-restore rule, lifted verbatim from `FieldRow.vue` so the two cannot drift. */
    function holdsValue(key: string): boolean {
        const raw = answers[key] ?? null;
        return raw !== null && typeof raw === 'object' && !Array.isArray(raw)
            ? Object.keys(raw).length > 0
            : !Coercion.isEmpty(raw as EngineValue);
    }

    function stepHoldsAnswer(step: RuntimeStep): boolean {
        if (step.isRepeat && step.sectionKey !== null) {
            const instances = answers[step.sectionKey];
            return Array.isArray(instances) && instances.length > 0;
        }
        return step.fieldKeys.some(holdsValue);
    }

    function isVisibleStep(key: string): boolean {
        return visibleSteps.value.some((s) => s.key === key);
    }

    /**
     * The step list as of the last change — the identity anchor the §4.2 rescue needs and the current list
     * cannot supply, because rescuing a vanished step means knowing where that step USED to sit.
     */
    let previousSteps: RuntimeStep[] = visibleSteps.value.slice();

    /**
     * Doc #27 §4.2 — walk OUTWARD from the vanished step's old position: backwards to the nearest surviving
     * predecessor first (the respondent's own history), then forwards.
     *
     * The rule this replaces was index arithmetic — `min(lastValidIndex, length - 1)` — and it knew a NUMBER
     * where it needed to know WHICH step vanished: on `[Lead, A, B, C]` with the respondent on C, an answer that
     * hid C, B and A at once clamped to `min(3, 0) = 0` and threw them back to step 1.
     *
     * Returns null when the walk finds nothing — either the graph is now empty (§4.1's terminal state) or the
     * key has no position in the order it was given. Each caller applies its own fallback, deliberately: the
     * rescue watcher falls back to the first step, while `goToStep()` falls through to §5.3's first-incomplete
     * rule. Folding one fallback in here would make the other unreachable.
     */
    function nearestSurvivor(vanishedKey: string, order: RuntimeStep[]): string | null {
        if (visibleSteps.value.length === 0) {
            return null;
        }
        const from = order.findIndex((s) => s.key === vanishedKey);
        if (from < 0) {
            return null;
        }
        for (let i = from - 1; i >= 0; i--) {
            if (isVisibleStep(order[i].key)) {
                return order[i].key;
            }
        }
        for (let i = from + 1; i < order.length; i++) {
            if (isVisibleStep(order[i].key)) {
                return order[i].key;
            }
        }
        return null;
    }

    function reconcileCurrentStep(): void {
        if (visibleSteps.value.length === 0 || isVisibleStep(currentStepKey.value)) {
            return;
        }
        setCurrentStep(nearestSurvivor(currentStepKey.value, previousSteps) ?? visibleSteps.value[0].key);
    }

    /**
     * ONE watcher over the step list itself, replacing the membership boolean this used to watch. The boolean
     * was the second, worse shape of Doc #27 §4.1: on a form empty at CONSTRUCTION the seed above never runs,
     * so `currentStepKey` stays `''`, the boolean is false at t=0 and STAYS false when steps appear — a Vue
     * watcher fires on change, so it never fired at all. The index then stayed -1, `attemptNext()` failed its
     * `idx >= 0` guard and fell through to `advanced: true` with no key change: Next did nothing, forever.
     *
     * Watching the list fires in both shapes, and Vue hands the handler the previous value for free — which is
     * exactly the identity anchor `nearestSurvivor()` needs.
     */
    watch(visibleSteps, (next, prev) => {
        const nextKeys = new Set(next.map((s) => s.key));
        const prevKeys = new Set(prev.map((s) => s.key));
        const gone = prev.filter((s) => !nextKeys.has(s.key));

        const vanished = currentStepKey.value !== '' && !nextKeys.has(currentStepKey.value) ? currentStepKey.value : null;

        let rescuedTo: string | null = null;
        if (vanished !== null) {
            rescuedTo = nearestSurvivor(vanished, prev);
            if (rescuedTo !== null) {
                setCurrentStep(rescuedTo);
            }
        } else if (currentStepIndex.value < 0 && next.length > 0) {
            // The never-seeded form, self-healing the moment its first step appears.
            setCurrentStep(next[0].key);
        }

        previousSteps = next.slice();
        lastStepChange.value = {
            added: next.filter((s) => !prevKeys.has(s.key)).map((s) => s.key),
            removed: gone.map((s) => s.key),
            removedWithAnswers: gone.filter(stepHoldsAnswer).map((s) => s.key),
            rescuedFrom: vanished,
            rescuedTo,
            previousCount: prev.length,
            count: next.length,
        };
    });

    // ── Flat interaction ───────────────────────────────────────────────────────────────────────
    function setAnswer(key: string, value: EngineValue | CompositeAnswer | GeoAnswer | null): void {
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

    // ── Piping (Increment H6b, Doc #26 §3/§4) ────────────────────────────────────────────────────
    /**
     * The answer document a hole renders against: the relevance-pruned effective answers OVERLAID with
     * the engine's `computed` map. The overlay is not a detail — `SemanticResult.computed` is a SEPARATE
     * map that is never merged into `effectiveAnswers`, and PHP persists
     * `array_merge($result->effectiveAnswers, $result->computed)` (`SubmissionPipeline.php`), which is the
     * document `SubmissionInboxPresenter` later renders from. Read `effectiveAnswers` alone here — the
     * natural, documented choice — and "Your total is ${total}" renders blank in the SPA and correct in
     * the inbox, silently killing the case §3.1 cites as the REASON `calculated` is pipeable.
     *
     * Pruned rather than retained, deliberately: a hidden field's value is kept but never submitted, so
     * piping it would promise the respondent an answer that will not be recorded. A hole naming a
     * currently-irrelevant field renders empty and returns intact when relevance does (§3.4).
     */
    const renderAnswers = computed<Record<string, unknown>>(() => ({
        ...result.value.effectiveAnswers,
        ...result.value.computed,
    }));

    /**
     * Per-instance merged answer maps for scoped rendering — the exact shape `SemanticValidator` builds
     * for a repeat instance's evaluation context and the `array_merge` {@link TemplateRenderer}'s docblock
     * prescribes, so §3.3 rule 2's "current instance" needs no addressable path.
     *
     * A `computed`, for two reasons. It invalidates with `result` for free; and — the important one — it
     * can never WRITE reactive state from inside a label's getter, which a mutable memo would, and that is
     * the recursive-update bug this design would otherwise be one refactor away from. Being lazy, a repeat
     * group with no piping never evaluates it at all. Memoised per INSTANCE, so N instances × M members
     * costs N merges rather than N×M.
     */
    const instanceRenderAnswers = computed<Record<string, Record<string, unknown>[]>>(() => {
        const base = renderAnswers.value;
        const out: Record<string, Record<string, unknown>[]> = {};

        for (const sectionKey of repeatSectionKeys) {
            const instances = base[sectionKey];
            if (!Array.isArray(instances)) {
                continue;
            }
            out[sectionKey] = (instances as InstanceAnswers[]).map((instance) => ({ ...base, ...instance }));
        }

        return out;
    });

    /** The answer map one render resolves against: flat, or one repeat instance's merged view. */
    function templateAnswers(scope?: RepeatScope): Record<string, unknown> {
        if (scope === undefined) {
            return renderAnswers.value;
        }

        // A missing instance (the section just went irrelevant, or an index raced a removal) falls back to
        // the flat map, so its same-instance holes render EMPTY rather than throwing — §3.4.
        return instanceRenderAnswers.value[scope.sectionKey]?.[scope.index] ?? renderAnswers.value;
    }

    /**
     * Resolve the locale variant, THEN fill its `${key}` holes — §4's normative order, enforced by
     * construction here so no call site can invert it (rendering first would fill holes into a string the
     * respondent is never going to see). The same locale goes to the renderer, so a piped choice answer
     * resolves its option label into the same language as the sentence around it (amendment A8).
     *
     * The `includes('${')` guard is not a micro-optimisation, it is the load-bearing line of this
     * increment: `templateAnswers()` is only CALLED inside the hole-bearing branch, so a hole-free label
     * never takes a reactive dependency on `result` at all. Every form authored before H6b is hole-free,
     * so every one keeps exactly the dependency graph it has today. The predicate is character-identical
     * to `TemplateParser.scan()`'s own early return, including the deliberate consequence that the `$${`
     * escape contains `${` and takes the slow path — which is correct; it must emit a literal `${`.
     */
    function templateText(base: string, translations: Record<string, string> | null, scope?: RepeatScope): string {
        const resolved = resolveText(base, translations, locale.value);

        if (!resolved.includes('${')) {
            return resolved;
        }

        return templateRenderer.render(resolved, templateSources, templateAnswers(scope), locale.value);
    }

    /** {@link templateText} for a nullable column (a hint, a placeholder, a section description). */
    function templateOptional(
        base: string | null,
        translations: Record<string, string> | null,
        scope?: RepeatScope,
    ): string | null {
        const resolved = resolveOptional(base, translations, locale.value);

        if (resolved === null || !resolved.includes('${')) {
            return resolved;
        }

        return templateRenderer.render(resolved, templateSources, templateAnswers(scope), locale.value);
    }

    function labelFor(field: RenderField, scope?: RepeatScope): string {
        return templateText(field.label, field.labelTranslations, scope);
    }

    /** A field's hint, locale-resolved then piped. `null` stays null — "no hint" is not "an empty hint". */
    function hintFor(field: RenderField, scope?: RepeatScope): string | null {
        return templateOptional(field.hint, field.hintTranslations, scope);
    }

    /**
     * A field's placeholder. Template-bearing per §6's closed list (the publish gate validates it), but it
     * has no `*_translations` sibling — no such column exists — so there is no locale variant to resolve.
     * Before H6b this was passed through RAW, so a published placeholder with a hole put `${key}` on a
     * respondent's screen, which §3.4 forbids outright.
     */
    function placeholderFor(field: RenderField, scope?: RepeatScope): string | null {
        return templateOptional(field.placeholder, null, scope);
    }

    /**
     * A section's own label/description. No scope parameter, and that is provable rather than a shortcut:
     * a section sits at position `(sequence, -1)` and its members at `(sequence, n >= 0)`, and
     * `TemplateScopeResolver::precedes()` is strict — so a section label referencing its own member is a
     * `template_forward_reference` and can never publish. Its holes can only name flat fields.
     */
    function sectionTitleFor(section: RenderSection): string {
        return templateText(section.label, section.labelTranslations);
    }

    function sectionDescriptionFor(section: RenderSection): string | null {
        return templateOptional(section.description, section.descriptionTranslations);
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
                                // Scoped, so the error-summary link reads the SAME question text the
                                // respondent sees on the field it jumps to (Increment H6b).
                                label: `${sectionLabel} ${index + 1}: ${labelFor(field, { sectionKey, index })}`,
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

    /** The error-summary banner's section label — piped, so it matches the heading it links to. */
    function sectionTitle(section: RenderSection): string {
        return sectionTitleFor(section);
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

        // The `idx >= 0` guard can no longer fall through: Increment H21b's list watcher guarantees a seeded,
        // resolvable key whenever there is at least one visible step, and `currentStep` above already returned
        // on an empty graph. Before that, a form empty at construction fell through here on EVERY click —
        // `advanced: true` with no key change, so the caller announced nothing and Next was dead forever.
        const idx = currentStepIndex.value;
        if (idx >= 0 && idx < visibleSteps.value.length - 1) {
            setCurrentStep(visibleSteps.value[idx + 1].key);
            return { advanced: true, errorCount: 0 };
        }
        return { advanced: true, errorCount: 0 };
    }

    function goPrev(): void {
        const idx = currentStepIndex.value;
        if (idx > 0) {
            setCurrentStep(visibleSteps.value[idx - 1].key);
        }
    }

    /**
     * The first visible step still holding an unanswered, currently-relevant question — §5.3's fallback when a
     * stored resume cursor cannot be resolved by position at all.
     *
     * "Unanswered" is the FIELD-LEVEL rule (`holdsValue`, lifted from `FieldRow.vue`), deliberately NOT
     * `DraftCompleteness`'s key-presence numerator. The server counts coverage of the authored form for a
     * reviewer's column; this answers a respondent's "where do I carry on", and a field they explicitly cleared
     * answers that with "here". The two numbers are separate by decision (Doc #27 §5.2) — do not converge them.
     */
    function firstIncompleteStepKey(): string | null {
        for (const step of visibleSteps.value) {
            if (step.isRepeat && step.sectionKey !== null) {
                if (instanceCount(step.sectionKey) === 0) {
                    return step.key;
                }
                continue;
            }
            if (step.fieldKeys.some((k) => fieldRelevance.value[k] === true && !holdsValue(k))) {
                return step.key;
            }
        }
        return null;
    }

    /**
     * Increment H21b, Doc #27 §5.3 — resolve a requested step key and REPORT how. This used to swallow an
     * unresolvable key silently, which is why all three resume-drift cases (the key is gone from this version;
     * the key exists but its branch is now irrelevant; `__lead__` after a republish moved those fields into a
     * section) landed the respondent on step 1 with no explanation, under a banner claiming 60% done.
     */
    function goToStep(key: string): StepResolution {
        if (visibleSteps.value.length === 0) {
            return 'none';
        }
        if (isVisibleStep(key)) {
            setCurrentStep(key);
            return 'exact';
        }
        // Known to this version but not currently visible → §4.2's nearest surviving predecessor, over the
        // FULL authored order rather than the visible one (the visible list is precisely what it fell out of).
        //
        // `__lead__` is included only when this version still HAS section-less fields. That is what degrades
        // §5.3's case (c) — a stored `__lead__` after a republish moved those fields into a section — into
        // case (a), an unknown key, exactly as the section specifies: there is no position left to walk from.
        const authoredOrder: RuntimeStep[] = [
            ...(renderModel.fields.some((f) => f.sectionKey === null)
                ? [{ key: LEAD_STEP_KEY, sectionKey: null, title: null, fieldKeys: [], isRepeat: false }]
                : []),
            ...renderModel.sections.map((s) => ({
                key: s.key,
                sectionKey: s.key,
                title: null,
                fieldKeys: [],
                isRepeat: s.isRepeatable,
            })),
        ];
        const survivor = nearestSurvivor(key, authoredOrder);
        if (survivor !== null) {
            setCurrentStep(survivor);
            return 'nearest';
        }
        setCurrentStep(firstIncompleteStepKey() ?? visibleSteps.value[0].key);
        return 'first-incomplete';
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
        isTerminal,
        lastStepChange,
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
        hintFor,
        placeholderFor,
        sectionTitleFor,
        sectionDescriptionFor,
        templateText,
        templateOptional,
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
        hasVisited,
    };
}
