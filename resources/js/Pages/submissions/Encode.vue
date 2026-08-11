<script setup lang="ts">
/**
 * Manual encoding page (Increment F4b; repeat groups G2; the step model H21c) — the first Submission Pipeline
 * channel with a UI. Renders the form's published version as a fillable form, collects answers, and POSTs
 * them to the pipeline in one submit.
 *
 * THIS PAGE MOUNTS THE GUEST RUNTIME'S OWN STORE (Increment H21c, Doc #27 §7). Until this increment the
 * encode channel had "no step key, no `single_page_mode` read, and zero occurrences of `relevant_expression`"
 * — a keyer saw every branch, including the ones the respondent's own answers exclude, and the pipeline
 * pruned what they typed at submit without saying so. `createFormRuntime()` is the SAME function the public
 * SPA mounts, so the two channels' step lists are equal by construction rather than by a hand-copied
 * predicate list, and every H21b behaviour (the terminal state, rescue by identity, retain-and-restore)
 * arrives with it. The import crosses from `resources/js/` into `resources/public-runtime/` for the first
 * time; the reverse edge already existed (the runtime imports this app's `FieldInput.vue`).
 *
 * WHAT THE STORE OWNS vs WHAT `blocks` OWNS. The store owns the MODEL: answers, relevance masks, the step
 * list, repeat-instance state. `blocks` — unchanged by H21c — stays the RENDER source, because it carries the
 * four things only this channel has (`supported`, `prefill`, `prefill_value`, `upload.url`) plus the
 * server-normalised option/cascade/grid/geo/media config `FieldInput` consumes. The two join on field key.
 *
 * VALIDATION STAYS SERVER-AUTHORITATIVE, and Next never blocks. The guest's `attemptNext()` refuses to
 * advance past a step holding errors; that is right for a respondent answering about themselves and wrong
 * for a keyer transcribing a document that may simply be incomplete. Blocking here would be a NEW refusal on
 * a channel that has never had one (the F4b contract above). Instead the pipeline's per-field 422s are fed
 * back into the store, and Submit raises a FORM-WIDE summary whose jump links carry the reader to the right
 * step first — Doc #27 §5.5's rule, reached from the opposite direction.
 *
 * A repeatable section (G2) renders an add/remove-instance loop: its answers live under the SECTION key as a
 * list of per-instance field-key→value maps (the exact nested shape the G1 pipeline persists), so an instance
 * field binds to `answers[sectionKey][i][fieldKey]` and its 422 keys `answers.<sectionKey>[i].<fieldKey>`; a
 * min/max count failure keys the bare `answers.<sectionKey>`.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { MdsBreadcrumb, MdsButton, MdsCard, type BreadcrumbItem } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import { createServerAutosave } from '@/composables/useServerAutosave';
import FieldInput, { type AnswerValue, type EncodeField } from '@/components/submissions/FieldInput.vue';
import {
    createFormRuntime,
    LEAD_STEP_KEY,
    type RuntimeStep,
} from '../../../public-runtime/composables/useFormRuntime';
import type { SchemaResponse } from '../../../public-runtime/lib/types';

interface Block {
    id: string | null;
    key: string | null;
    label: string | null;
    description: string | null;
    repeatable: boolean;
    min_instances: number | null;
    max_instances: number | null;
    fields: EncodeField[];
}

// The scheduled-form block (Increment H12b) — the twin of the guest presenter's, emitted by EncodeFormPresenter.
interface ScheduleBlock {
    opens_at: string | null;
    closes_at: string | null;
    timezone: string;
    max_responses: number | null;
    acceptance: 'open' | 'opens_soon' | 'closed' | 'capacity_reached';
    remaining: number | null;
}

/** One step of the SERVER's projection (H21c) — the first paint, before the store has evaluated anything. */
interface ServerStep {
    key: string;
    section_key: string | null;
    field_keys: string[];
    is_repeat: boolean;
}

const props = defineProps<{
    form: {
        id: string;
        title: string;
        description: string | null;
        default_locale: string;
        supported_locales: string[];
        single_page_mode: boolean;
        schedule: ScheduleBlock;
    };
    version: {
        id: string;
        version_number: number;
        checksum: string;
        schema: SchemaResponse['version']['schema'];
        now: string;
    };
    blocks: Block[];
    steps: ServerStep[];
    /**
     * The saved draft being resumed, or null on the blank keying form (Increment I9b). Its presence is the
     * single test for "am I in resume mode" — one nullable object rather than five scalars, so this block
     * grows by exactly one entry.
     */
    draft: {
        id: string;
        client_submission_uuid: string;
        answers: Record<string, unknown>;
        current_step: string | null;
        completeness_percent: number | null;
        last_saved_at: string | null;
        expires_at: string | null;
    } | null;
    /**
     * The FINALIZED submission being corrected, or null (Increment I9c). Mutually exclusive with `draft` by
     * construction — `EncodeFormPresenter` derives both from one `?Submission`, branching on its status — so
     * "which of the three modes am I in" is answerable without the two ever contradicting each other.
     */
    editing: {
        id: string;
        answers: Record<string, unknown>;
        status: string;
        /**
         * The optimistic-concurrency token: the checksum of the document THIS page was rendered from. Sent
         * back on the PATCH so the server can refuse a stale page instead of silently reverting whatever
         * another editor saved in the meantime — which also covers browser-Back-then-Save.
         */
        baseline: string | null;
        demotes_on_save: boolean;
    } | null;
    /** The PATCH target in edit mode; null otherwise. */
    update_url: string | null;
    /** The autosave endpoint. NULL IN EDIT MODE — an edit must never reach the draft channel. */
    draft_url: string | null;
    /**
     * The trail back, resolved SERVER-SIDE by `CrumbTrail` (Increment J2d).
     *
     * ⚠️ THE THREE-OR-FIVE BRANCH IS GONE, and its absence is the improvement. This page rebuilt the trail
     * from `isEditing` / `draft === null` — re-deriving on the client a mode each of the three controllers
     * already knows by construction, with three hard-coded URL expressions that could disagree with the
     * route that rendered them.
     */
    crumbs: BreadcrumbItem[];
    /**
     * Where Cancel leaves TO — `CrumbTrail::exitFrom()`, the crumb immediately BEFORE the tail.
     *
     * ⚠️ NOT THE TAIL: the tail is where you ARE ("Edit answers", "New response"); Cancel is where you leave
     * to — the submission in edit mode, the form otherwise. An earlier note here said the tail must agree
     * with Cancel, which is false in both modes. Deriving it server-side from the same trail is what makes
     * the agreement structural rather than a docblock asking the next author to remember; the invariant is
     * asserted in `CrumbTrailReachabilityTest`.
     *
     * ⚠️ NULL IS A REAL OUTCOME — a reader refused that destination must not be offered a Cancel that 403s,
     * so both call sites below are `v-if`-guarded rather than defaulting to a URL.
     */
    cancel_url: string | null;
}>();

/**
 * Increment I9c. Read this instead of `editing !== null` at each site so the three modes stay one question
 * with one answer.
 *
 * ⚠️ `!= null`, NOT `!== null`, and the difference is not pedantry. `undefined !== null` is TRUE, so a strict
 * check puts the page into EDIT mode whenever the prop is merely ABSENT — with `editing` undefined, which the
 * template then dereferences. The presenter always sends the key, so production never hits it; every existing
 * encode test omits it, and all seventeen of them failed on the strict form. Absent and null mean the same
 * thing here — "there is no submission being corrected" — and the predicate has to say so.
 */
const isEditing = computed(() => props.editing != null);


const page = usePage();

// ── The runtime store ────────────────────────────────────────────────────────────────────────────────────
// `search: ''` is what keeps H7's URL prefill off this channel: a keyer is the SOURCE of a `url`-sourced
// value, not a recipient of one, so reading the tenant app's query string here would silently prefill from an
// unrelated parameter. `buildPrefill` still seeds `fixed` literals, which is what lets a label pipe them.
//
// `now` comes from the SERVER (`version.now`), not from `isoClock(new Date())` as the guest does — see
// `EncodeFormPresenter::clock()`. Same clock in, same step list out.
// On a RESUME (Increment I9b) the store is seeded from the draft rather than started empty, and the uuid is
// adopted rather than re-minted. Re-minting is the subtle half: the uuid is the idempotency key, so a fresh
// one would make Submit look like a brand-new response and leave the draft row behind, unpromoted, until the
// reaper deleted it.
const runtime = createFormRuntime(props as unknown as SchemaResponse, {
    now: props.version.now,
    initialLocale: props.form.default_locale,
    search: '',
    // I9c seeds from `editing` on the same footing: the store does not care which kind of stored document it
    // restores, only that there is one. No uuid is adopted in edit mode — the submission is identified by its
    // id in the PATCH URL, and minting or adopting an idempotency key here would be a second identifier for a
    // request that already has an authorized one.
    initialAnswers: (props.draft?.answers ?? props.editing?.answers) as Record<string, never> | undefined,
    initialClientSubmissionUuid: props.draft?.client_submission_uuid,
});

// Seed each repeatable section's `min_instances` starter rows. The GUEST deliberately opens a repeat group
// empty (an empty guest load has to be axe-clean), but this channel has always opened with the required rows
// ready to fill — E2eSeeder records that difference by name — and a keyer copying a paper roster should not
// have to press Add before they can start.
for (const section of runtime.renderModel.sections) {
    if (!section.isRepeatable) {
        continue;
    }
    while (runtime.instanceCount(section.key) < runtime.minInstances(section.key)) {
        if (runtime.addInstance(section.key) < 0) {
            break; // `max_instances` below `min_instances` — an authoring fault, not a loop to hang on
        }
    }
}

// ── Server errors ────────────────────────────────────────────────────────────────────────────────────────
// Inertia surfaces the pipeline's 422 as `errors['answers.<path>']`. The rows below read that map directly
// (unchanged since F4b), and it is ALSO handed to the store so `erroredItems` can address each failure to its
// step for the summary banner. Stripping the `answers.` prefix is the same normalisation
// `lib/error-normalizer.ts` performs for the guest channel's `validation_failed` shape.
const pageErrors = computed<Record<string, string | undefined>>(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

watch(
    pageErrors,
    (errors) => {
        const addressed: Record<string, string[]> = {};
        for (const [key, message] of Object.entries(errors)) {
            if (message === undefined || !key.startsWith('answers.')) {
                continue;
            }
            addressed[key.slice('answers.'.length)] = [message];
        }
        runtime.setServerErrors(addressed);
        if (Object.keys(addressed).length > 0) {
            // Ungates the summary: `erroredItems` only reports what the respondent has "seen", and a keyer
            // who has just pressed Submit has seen all of it.
            runtime.markSubmitAttempted();
        }
    },
    { immediate: true, deep: true },
);

const summaryItems = computed(() => runtime.erroredItems.value);

// ── Steps ────────────────────────────────────────────────────────────────────────────────────────────────
const visibleSteps = computed<RuntimeStep[]>(() => runtime.visibleSteps.value);

/**
 * Single-page mode stacks every visible step; the stepped flow renders exactly one. `currentStep` is null
 * only in the terminal state, which the template handles separately, so the fallback is never reached with a
 * non-empty list.
 */
const shownSteps = computed<RuntimeStep[]>(() => {
    if (props.form.single_page_mode) {
        return visibleSteps.value;
    }
    const current = runtime.currentStep.value;
    return current === null ? [] : [current];
});

const stepPosition = computed(() => `Step ${runtime.currentStepIndex.value + 1} of ${visibleSteps.value.length}`);

const blockByStepKey = computed<Record<string, Block>>(() => {
    const map: Record<string, Block> = {};
    for (const block of props.blocks) {
        map[block.key ?? LEAD_STEP_KEY] = block;
    }
    return map;
});

function blockFor(step: RuntimeStep): Block | null {
    return blockByStepKey.value[step.key] ?? null;
}

/**
 * The rows to render inside a step, in `blocks` order.
 *
 * Driven from the BLOCK rather than from `step.fieldKeys`, and the difference is H7's asymmetry: the step's
 * key list is the RESPONDENT's, so it excludes `hidden` entirely. A `url`-sourced hidden field sitting
 * alongside ordinary questions must still render inline for the keyer — it is only when its whole section is
 * absent from the step list that it moves to the reference block below.
 */
function rowsOf(step: RuntimeStep): EncodeField[] {
    const block = blockFor(step);
    if (block === null) {
        return [];
    }
    if (step.isRepeat) {
        return block.fields; // per-instance relevance is applied per row inside the loop
    }
    return block.fields.filter((field) => runtime.fieldRelevance.value[field.key] !== false);
}

// ── The keyer-only reference block (H21c, decision (2)) ───────────────────────────────────────────────────
/**
 * `url`-prefilled hidden fields whose step is not in the visible list — a section holding nothing else can
 * never BE a step, because `RENDERS_NOTHING` drops `hidden` and the emptiness predicate is a static type
 * test. The respondent never sees such a field; a keyer transcribing paper is the only possible source of
 * its value (H7, `data-dictionary.md`'s read/render asymmetry), so it renders here rather than nowhere.
 *
 * Deliberately OUTSIDE the step list rather than inside a widened projection: keeping the two channels'
 * step lists byte-identical is what makes the parity assertion a clean equality.
 */
const referenceRows = computed<EncodeField[]>(() => {
    const shown = new Set(visibleSteps.value.map((step) => step.key));
    const rows: EncodeField[] = [];
    for (const block of props.blocks) {
        if (shown.has(block.key ?? LEAD_STEP_KEY)) {
            continue;
        }
        for (const field of block.fields) {
            if (field.field_type === 'hidden' && field.prefill === 'url') {
                rows.push(field);
            }
        }
    }
    return rows;
});

// ── Schedule (H12b) ──────────────────────────────────────────────────────────────────────────────────────
const isOpen = computed(() => props.form.schedule.acceptance === 'open');

function formatInstant(iso: string): string {
    try {
        const formatted = new Intl.DateTimeFormat('en', {
            timeZone: props.form.schedule.timezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));
        return `${formatted} (${props.form.schedule.timezone})`;
    } catch {
        return iso;
    }
}

const scheduleNotice = computed<{ title: string; body: string } | null>(() => {
    // I9c — the schedule says nothing about an EDIT. A correction consumes no capacity slot and is not a new
    // response, so `assertCapacity`/`assertCanStart` never run on this path; telling an editor "this form is
    // full" over a working Save button is the same defect the resume branch below fixed, in a louder form.
    if (isEditing.value) {
        return null;
    }

    const schedule = props.form.schedule;
    switch (schedule.acceptance) {
        case 'opens_soon':
            return {
                title: 'This form isn’t open yet',
                body: schedule.opens_at
                    ? `It opens on ${formatInstant(schedule.opens_at)}. Submissions are blocked until then.`
                    : 'Submissions are blocked until it opens.',
            };
        case 'closed':
            // On a RESUMED draft the copy must not contradict the enabled Submit button beside it (I9b).
            // H12a's grace window lets a draft STARTED before the close still be finalized, so "new
            // submissions are no longer accepted" is true and "you cannot submit this" is not — an alert
            // saying the latter over a working button is how a keyer learns to distrust the banners.
            return props.draft !== null
                ? {
                      title: 'This form has closed',
                      body: 'You can still submit this draft, because it was started before the form closed. New responses cannot be started.',
                  }
                : {
                      title: 'This form is closed',
                      body: schedule.closes_at
                          ? `It closed on ${formatInstant(schedule.closes_at)}. New submissions are no longer accepted.`
                          : 'New submissions are no longer accepted.',
                  };
        case 'capacity_reached':
            return {
                title: 'This form is full',
                body: 'It has reached its response limit. New submissions are no longer accepted.',
            };
        default:
            return null;
    }
});

// Increment H21c (Doc #27 §7) — the answers RELEVANCE dropped, flashed by the controller after a submit that
// SUCCEEDED. With the store mounted this is now rare: the client hides what the server would prune. It stays
// as the divergence alarm for the engine-degrade path, a page held open across a republish, and a direct POST.
const prunedAnswers = computed<string[]>(() => page.props.flash?.prunedAnswers ?? []);
const prunedDismissed = ref(false);

// ── Rows ─────────────────────────────────────────────────────────────────────────────────────────────────
function flatValue(fieldKey: string): AnswerValue {
    return (runtime.answers[fieldKey] ?? null) as AnswerValue;
}

function setFlatValue(fieldKey: string, value: AnswerValue): void {
    runtime.setAnswer(fieldKey, value as never);
    runtime.markTouched(fieldKey);
    armAutosave();
}

function fieldError(fieldKey: string): string | undefined {
    const errors = pageErrors.value;
    const exact = errors[`answers.${fieldKey}`];
    if (exact !== undefined) {
        return exact;
    }
    // A composite grid (Increment G4b) reports cell errors at `answers.<field>.<row>[.<col>]`; surface the
    // first as the whole-field error so the grid's aria-live region shows it.
    const prefix = `answers.${fieldKey}.`;
    const cellKey = Object.keys(errors).find((key) => key.startsWith(prefix));
    return cellKey !== undefined ? errors[cellKey] : undefined;
}

function instanceError(sectionKey: string, index: number, fieldKey: string): string | undefined {
    return pageErrors.value[`answers.${sectionKey}[${index}].${fieldKey}`];
}

function countError(sectionKey: string): string | undefined {
    return pageErrors.value[`answers.${sectionKey}`];
}

function instanceValue(sectionKey: string, index: number, fieldKey: string): AnswerValue {
    return runtime.instanceValue(sectionKey, index, fieldKey) as AnswerValue;
}

/**
 * Adding or removing a repeat instance IS an edit, and routing both through here is what makes autosave see
 * it. Wired straight from the template, `runtime.addInstance`/`removeInstance` mutate the answer map without
 * ever arming autosave — so a session whose only change was structural (a keyer deleting the two seeded roster
 * rows a household does not need) was silently never persisted, and the page showed no save indicator at all
 * because none had ever run.
 */
function addInstance(sectionKey: string): void {
    runtime.addInstance(sectionKey);
    armAutosave();
}

function removeInstance(sectionKey: string, index: number): void {
    runtime.removeInstance(sectionKey, index);
    armAutosave();
}

function setInstanceValue(sectionKey: string, index: number, fieldKey: string, value: AnswerValue): void {
    runtime.setInstanceAnswer(sectionKey, index, fieldKey, value as never);
    runtime.markInstanceTouched(sectionKey, index, fieldKey);
    armAutosave();
}

function boundsHint(block: Block): string | null {
    const lo = block.min_instances ?? 0;
    const hi = block.max_instances;
    if (lo > 0 && hi !== null) {
        return `Add ${lo} to ${hi}.`;
    }
    if (lo > 0) {
        return `Add at least ${lo}.`;
    }
    if (hi !== null) {
        return `Add up to ${hi}.`;
    }
    return null;
}

function instanceLegend(block: Block, index: number): string {
    return `${block.label ?? 'Entry'} ${index + 1}`;
}

// ── Navigation ───────────────────────────────────────────────────────────────────────────────────────────
// Next NEVER blocks — see the module docblock. `goToStep` is used rather than `attemptNext()` precisely
// because `attemptNext()` is the gate; the resolution it returns is ignored here because the destination is
// taken from the CURRENT visible list, so it is always `'exact'`.
function goNext(): void {
    const next = visibleSteps.value[runtime.currentStepIndex.value + 1];
    if (next !== undefined) {
        runtime.goToStep(next.key);
    }
}

function goPrev(): void {
    runtime.goPrev();
}

/**
 * The anchor a summary jump lands on. `FieldInput` emits no id of its own — `MdsFormField` derives the input
 * id from Vue's `useId()`, a `v-0`-style token that has nothing to do with the field key — so this page has
 * to stamp its own wrapper, exactly as the guest runtime's `FieldRow`/`InstanceField` do.
 *
 * The prefix is `encode-field-` rather than the runtime's `field-` on purpose: these anchors are this page's,
 * and borrowing the guest's prefix would make a future shared helper silently resolve the wrong element on a
 * page that happened to mount both.
 */
function anchorFor(address: string): string {
    return `encode-field-${address}`;
}

/**
 * Jump to the field an error summary entry addresses. The step has to change FIRST and the DOM has to settle
 * before the anchor exists — in stepped mode the field is on an unmounted step, which is the trap H21b's
 * `SummaryBanner` `beforeJump` prop was added for.
 *
 * Focus goes to the first focusable INSIDE the anchor, not to the anchor: the wrapper is a plain `div` with
 * no `tabindex`, so `.focus()` on it is a silent no-op — the same reason `SummaryBanner.vue` reaches for
 * `querySelector('input, select, textarea, button, [tabindex]')`.
 */
async function jumpTo(item: { address: string; stepKey: string }): Promise<void> {
    if (!props.form.single_page_mode && item.stepKey !== runtime.currentStepKey.value) {
        runtime.goToStep(item.stepKey);
        await nextTick();
    }
    const target = document.getElementById(anchorFor(item.address));
    if (target === null) {
        return;
    }
    target.scrollIntoView({ block: 'center' });
    target.querySelector<HTMLElement>('input, select, textarea, button, [tabindex]')?.focus();
}

// ── Autosave (Increment I9b) ─────────────────────────────────────────────────────────────────────────────
// ⚠️ ARMED ON THE FIRST REAL EDIT, NOT AT SETUP, and this is not defensive politeness. The min-instance
// seeding loop above calls `addInstance()`, which MUTATES `runtime.answers` during mount — so a deep watcher
// armed here would fire on every page view of any form with a repeatable section and create an empty
// `status = draft` row, with a 30-day TTL, for a keyer who never typed a character.
const autosaveArmed = ref(false);

const autosave = createServerAutosave({
    // Empty string in edit mode, where `enabled` is false forever and no request is ever built. The presenter
    // sends null there deliberately (see `EncodeFormPresenter::present`): an edit autosaved down the draft
    // channel would overwrite a respondent's answers with no policy check for `update` and no audit row.
    url: props.draft_url ?? '',
    clientSubmissionUuid: runtime.clientSubmissionUuid,
    answers: runtime.answers,
    currentStepKey: runtime.currentStepKey,
    // Also gated on the schedule: `saveDraft()` runs `assertCanStart()` on the CREATE branch, so on a closed
    // form the first tick would 403 while later ones (once a draft exists) succeed — one red flash, then
    // silence. Erring one case strict (`capacity_reached` would actually allow a draft create) is the right
    // side to err on.
    //
    // ⚠️ AND NEVER IN EDIT MODE (I9c). An edit is an explicit, audited act with a demotion consequence — a
    // background tick that silently sends an approved submission back for re-review is the opposite of what
    // autosave is for. This is the belt to the presenter's null-URL braces; either alone would look
    // sufficient, which is why both are here.
    enabled: computed(() => autosaveArmed.value && !isEditing.value && (isOpen.value || props.draft !== null)),
});

function armAutosave(): void {
    autosaveArmed.value = true;
}

/** The header indicator. Null before the first save, so a fresh page carries no stale "Saved" claim. */
const autosaveLabel = computed<string | null>(() => {
    switch (autosave.state.value) {
        case 'saving':
            return 'Saving…';
        case 'saved':
            return autosave.completeness.value === null
                ? 'Draft saved'
                : `Draft saved · ${autosave.completeness.value}% complete`;
        case 'error':
            return 'Not saved — retrying';
        case 'stopped':
            return 'Not saved';
        default:
            return null;
    }
});

function formatDay(iso: string | null): string | null {
    if (iso === null) {
        return null;
    }
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString();
}

const draftSavedLabel = computed(() => {
    const day = formatDay(props.draft?.last_saved_at ?? null);

    return day === null ? '' : ` from ${day}`;
});

const draftExpiryLabel = computed(() => {
    const day = formatDay(props.draft?.expires_at ?? null);

    return day === null ? '' : ` If you do not come back to it, it is removed on ${day}.`;
});

// Restore the resume cursor. After mount, so the store's own step reconciliation has settled first; a key
// that no longer resolves (the schema moved, or the step is no longer relevant under the restored answers)
// is a guarded no-op in the store rather than an error here.
if (props.draft?.current_step != null && props.draft.current_step !== '') {
    void nextTick(() => runtime.goToStep(props.draft!.current_step as string));
}

// ── Submit ───────────────────────────────────────────────────────────────────────────────────────────────
const submitting = ref(false);

/**
 * Increment I9b — a RESUMED draft may be submitted after the form has closed.
 *
 * The old gate was `isOpen` alone, which blocked exactly the grace window `FormAcceptanceGuard::assertCanPromote()`
 * exists to allow: a draft STARTED before the close may still be promoted after it, so a keyer mid-transcription
 * when the window shuts is not stranded. This read as correct defensive UI and was the opposite — the client
 * refusing what the server would have accepted. `capacity_reached` deliberately still blocks: the cap is
 * enforced transactionally at finalize and would 403 anyway.
 */
const canSubmit = computed(
    () =>
        // I9c — an EDIT is never schedule-gated. It creates no submission, consumes no capacity slot, and the
        // service runs neither `assertCanStart()` nor `assertCapacity()`. Blocking Save on a closed or full
        // form would make every historical submission on a finished survey permanently uncorrectable, which
        // is exactly the population most likely to need a correction.
        isEditing.value || isOpen.value || (props.draft !== null && props.form.schedule.acceptance === 'closed'),
);

function submit(): void {
    // Increment H12b — never POST a submission the schedule guard will 403 (the button is disabled too).
    if (!canSubmit.value) {
        return;
    }

    if (isEditing.value) {
        submitEdit();

        return;
    }

    // ⚠️ STOP AUTOSAVING BEFORE THE SUBMIT GOES OUT. A debounce timer armed within the last 1500 ms would
    // otherwise fire while the promote is in flight: the draft POST blocks on the promoted row's
    // `lockForUpdate`, comes back 409 `draft_already_finalized`, and the composable — correctly, by its own
    // rules — renders the red "Your changes are no longer being saved" alert over a submission that in fact
    // succeeded. Disposing here is not merely cosmetic: `dispose()` flushes any pending edit first, so the
    // last keystroke before Submit is still captured, and Submit itself posts the full answer map anyway.
    autosave.dispose();
    runtime.markSubmitAttempted();
    // The FULL answer map, not `effectiveAnswers`. The guest posts the pruned set; this channel deliberately
    // posts everything so the server's own prune stays observable — that is what keeps `prunedAnswers` above
    // meaningful as a divergence alarm rather than a message that can never fire.
    //
    // Detached from the reactive proxy: Inertia serialises whatever it is handed, and posting the live map
    // would let a keystroke landing mid-flight change the body under the request.
    const answers = JSON.parse(JSON.stringify(runtime.answers)) as Record<string, never>;

    router.post(
        `/forms/${props.form.id}/submissions`,
        // The uuid routes a resumed draft to `promote()` (flipping the SAME row) instead of `submit()`
        // (creating a second one), and makes a double-clicked Submit resolve to one submission via Stage 2b.
        { answers, client_submission_uuid: runtime.clientSubmissionUuid },
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
            // The controller redirects back to this same page either way, so the flag decides what happens to
            // everything the keyer typed. A 422 MUST keep it — losing a page of transcription to a missing
            // required field would be worse than the defect this increment fixes. A success must NOT: the
            // component remounts, `createFormRuntime` runs again and the min-instance seeding with it, which
            // is a cleaner next entry than F4b's `defaults()` + `reset()` pair now that the store owns the
            // answer map (and the store exposes no reset seam, deliberately — the guest never needs one).
            preserveState: (page) => Object.keys(page.props.errors ?? {}).length > 0,
        },
    );
}

/**
 * Increment I9c — apply a correction to an already-finalized submission.
 *
 * A PATCH to the submission's own route, never the encode POST: that endpoint's whole job is to CREATE a
 * submission, and reaching it with an existing row's answers would produce a second response rather than
 * correct the first.
 *
 * No `client_submission_uuid` in the body. The submission is identified by the URL, which the policy has
 * already authorized; adding a caller-chosen second identifier is the two-independent-inputs shape that
 * produced I9b's cross-form draft hole.
 *
 * ⚠️ `preserveState` IS THE OPPOSITE OF THE SUBMIT PATH'S, and deliberately. There, a success remounts the
 * page so the next entry starts clean. Here, a success REDIRECTS to the detail view, so nothing is preserved
 * either way — and a 422 must keep every edit the user made, which is what the errors-length check does.
 * Getting this backwards would silently discard a page of corrections on one failed constraint.
 */
function submitEdit(): void {
    runtime.markSubmitAttempted();

    // Detached from the reactive proxy for the same reason the submit path detaches: Inertia serialises
    // whatever it is handed, and a keystroke landing mid-flight would change the body under the request.
    //
    // The FULL answer map, including the media keys the server is about to discard. Filtering them here
    // would be the client asserting a rule the server owns — `mergeMedia()` takes media from the STORED
    // document regardless of what arrives, so sending them changes nothing and omitting them proves nothing.
    const answers = JSON.parse(JSON.stringify(runtime.answers)) as Record<string, never>;

    router.patch(
        props.update_url as string,
        // `baseline` is the whole of this channel's concurrency story — see the prop's docblock. It is sent
        // even when null so the server's `required` rule rejects a page too old to have one, rather than
        // letting a blind whole-document write through.
        { answers, baseline: props.editing?.baseline },
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
            preserveState: (page) => Object.keys(page.props.errors ?? {}).length > 0,
        },
    );
}
</script>

<template>
    <div class="encode">
        <Head :title="isEditing ? `Edit answers — ${form.title}` : `Encode — ${form.title}`" />

        <PageHeader
            :title="isEditing ? 'Edit answers' : draft === null ? 'New submission' : 'Continue submission'"
            icon="submissions"
        >
            <template #breadcrumbs>
                <!--
                    Increment J2c — a real trail rather than a single hand-rolled back link, and the
                    destination changed as well as the markup. It used to be a bare `← Forms`, which threw a
                    keyer who arrived from ONE form back to the list of all of them; the trail now passes
                    through that form's hub. In edit mode the tail is the submission, because the editor
                    arrived from the detail page and that is where Save returns them — the crumb and the
                    Cancel action below must keep naming the same place.
                -->
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
            <template #actions>
                <!-- The autosave indicator lives in the header, beside Cancel, because that is where a keyer
                     looks before leaving the page. `aria-live="polite"` and not `assertive`: it changes on
                     every debounce tick, and an assertive region would interrupt a screen reader mid-question
                     on a page whose whole job is uninterrupted typing. -->
                <!-- Always PRESENT, empty until there is something to say. A live region inserted into the
                     DOM with its content already in it is not reliably announced — the assistive technology
                     has to be observing the node before the text changes — so `v-if` here would silently
                     swallow the first "Draft saved". -->
                <span class="encode__autosave" role="status" aria-live="polite">{{ autosaveLabel ?? '' }}</span>
                <!-- ONE destination, derived from the trail (J2d): `cancel_url` is the crumb immediately
                     before the tail. It was two hard-coded branches here and two more in the sticky footer,
                     kept in step with the trail by hand. `v-if` rather than a fallback URL — a reader the
                     destination refuses is offered no Cancel at all, which is the whole point of the sweep. -->
                <Link v-if="cancel_url" :href="cancel_url" class="encode__cancel">Cancel</Link>
            </template>
        </PageHeader>

        <p class="encode__intro">
            <template v-if="isEditing">
                Correcting a recorded response for <strong>{{ form.title }}</strong> (v{{ version.version_number }}).
            </template>
            <template v-else>
                Encoding a response for <strong>{{ form.title }}</strong> (v{{ version.version_number }}).
            </template>
        </p>

        <!-- Edit banner (I9c). The demotion is announced BEFORE any typing, not discovered at Save: an
             editor fixing one character in an approved response is entitled to know that saving withdraws
             the approval.
             ⚠️ `role="status"`, NOT `role="alert"`, EVEN FOR THE WARNING — and the reason is written twenty
             lines above this in the autosave note: a live region that is inserted into the DOM with its
             content already in it is not reliably announced, because assistive tech has to be observing the
             node before the text changes. This banner is present at first paint and never changes (
             `demotes_on_save` is a server prop), so `alert` buys nothing on the readers that ignore
             load-time alerts and INTERRUPTS on the ones that do not. The warning is carried by the heading
             text and the accent, which is where it belongs. -->
        <div
            v-if="isEditing"
            class="encode__editing"
            :class="{ 'encode__editing--warning': editing!.demotes_on_save }"
            role="status"
        >
            <strong class="encode__editing-title">
                {{ editing!.demotes_on_save ? 'This response has been approved' : 'Editing a recorded response' }}
            </strong>
            <span class="encode__editing-body">
                <template v-if="editing!.demotes_on_save">
                    <!-- ⚠️ "under review", not "the review queue". The service sets `under_review`, not
                         `submitted` — a reviewer filtering the inbox on Submitted will NOT see it come back,
                         and an earlier draft of this sentence promised exactly that. -->
                    Saving a change withdraws the approval and moves this response back to
                    <strong>under review</strong>, so a reviewer has to decide it again. Every change is
                    recorded in the audit log.
                </template>
                <template v-else>
                    You are changing answers that have already been submitted. Every change is recorded in the
                    audit log, with your name against it.
                </template>
            </span>
        </div>

        <!-- Resume banner (I9b). Two jobs: confirm that what is on screen is the keyer's own saved work
             rather than a blank form, and name the expiry, because the reaper HARD-deletes an expired draft
             and a silent disappearance is the worst version of this feature. -->
        <div v-if="draft !== null" class="encode__resume" role="status">
            <strong class="encode__resume-title">Continuing a saved draft</strong>
            <span class="encode__resume-body">
                Your answers were restored{{ draftSavedLabel }}. This draft is saved as you type and is not
                submitted until you press Submit.{{ draftExpiryLabel }}
            </span>
        </div>

        <!-- The one autosave failure a keyer must act on rather than wait out (a submitted-elsewhere draft or
             an expired session). `role="alert"` because unlike the indicator above, continuing to type after
             this is wasted work. -->
        <div v-if="autosave.state.value === 'stopped'" class="encode__degraded" role="alert">
            <strong class="encode__degraded-title">Your changes are no longer being saved</strong>
            <span class="encode__degraded-body">{{ autosave.message.value }}</span>
        </div>

        <!-- Pruned-answer report (H21c): the previous submission WAS recorded — the copy must say so while
             naming what relevance removed from it. -->
        <div
            v-if="prunedAnswers.length > 0 && !prunedDismissed"
            class="encode__pruned"
            role="status"
            aria-live="polite"
        >
            <div class="encode__pruned-body">
                <strong class="encode__pruned-title">
                    Recorded, but {{ prunedAnswers.length }}
                    {{ prunedAnswers.length === 1 ? 'answer was' : 'answers were' }} not saved
                </strong>
                <p class="encode__pruned-lede">
                    These questions did not apply to the answers given, so they were left out of the response:
                </p>
                <ul class="encode__pruned-list">
                    <li v-for="(entry, i) in prunedAnswers" :key="i">{{ entry }}</li>
                </ul>
            </div>
            <MdsButton variant="tertiary" icon-left="close" @click="prunedDismissed = true">Dismiss</MdsButton>
        </div>

        <!-- The engine degraded on a malformed published expression (H21c): every question is shown and the
             server stays authoritative. Said out loud rather than swallowed — silently showing every branch
             is exactly the pre-H21c behaviour this page exists to end. -->
        <div v-if="runtime.engineFailed.value" class="encode__degraded" role="alert">
            <strong class="encode__degraded-title">Conditions could not be applied</strong>
            <span class="encode__degraded-body">
                Every question is shown. Answers that do not apply will still be removed when you submit.
            </span>
        </div>

        <!-- Scheduled-form pre-warning (H12b): the form is visible for reference, but submitting is blocked. -->
        <div v-if="scheduleNotice" class="encode__schedule-banner" role="alert">
            <strong class="encode__schedule-title">{{ scheduleNotice.title }}</strong>
            <span class="encode__schedule-body">{{ scheduleNotice.body }}</span>
        </div>

        <form class="encode__form" @submit.prevent="submit">
            <!-- Form-WIDE error summary (H21c). Doc #27 §5.5: Submit reports across the whole form, because a
                 step-scoped banner on a multi-step form announces "0 fields need your attention" while the
                 submit is refused elsewhere. Each jump changes step before it focuses. -->
            <div v-if="summaryItems.length > 0" class="encode__summary" role="alert" aria-live="assertive">
                <strong class="encode__summary-title">
                    {{ summaryItems.length }}
                    {{ summaryItems.length === 1 ? 'answer needs' : 'answers need' }} attention
                </strong>
                <ul class="encode__summary-list">
                    <li v-for="item in summaryItems" :key="item.address">
                        <button type="button" class="encode__summary-link" @click="jumpTo(item)">
                            {{ item.label }}
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Keyer-only reference rows (H21c) — never shown to a respondent, so never part of a step. -->
            <MdsCard v-if="referenceRows.length > 0">
                <div class="encode__block">
                    <div class="encode__block-head">
                        <h2 class="encode__block-title">Reference fields</h2>
                        <p class="encode__block-desc">
                            Not shown to the respondent. These values normally arrive from the form’s link —
                            enter them here when you are keying from paper.
                        </p>
                    </div>
                    <div class="encode__fields">
                        <div v-for="field in referenceRows" :key="field.key" :id="anchorFor(field.key)">
                            <FieldInput
                                :field="field"
                                :model-value="flatValue(field.key)"
                                :error="fieldError(field.key)"
                                :read-only="isEditing"
                                @update:model-value="setFlatValue(field.key, $event)"
                            />
                        </div>
                    </div>
                </div>
            </MdsCard>

            <!-- Doc #27 §4.1's TERMINAL state, reached on this channel too: no counter (never "Step 1 of 0"),
                 an explicit panel, and ONE labelled Submit. `isTerminal` carries this rather than
                 `isLastStep`, which is correctly false at zero steps. -->
            <MdsCard v-if="runtime.isTerminal.value">
                <div class="encode__terminal">
                    <h2 class="encode__block-title">No questions to answer</h2>
                    <p class="encode__block-desc">
                        Nothing in this form applies to the answers given so far. You can still record the
                        response as it stands.
                    </p>
                </div>
            </MdsCard>

            <template v-else>
                <p v-if="!form.single_page_mode" class="encode__progress" aria-live="polite">
                    {{ stepPosition }}
                </p>

                <MdsCard v-for="step in shownSteps" :key="step.key">
                    <div class="encode__block">
                        <div
                            v-if="blockFor(step)?.label || blockFor(step)?.description || boundsHint(blockFor(step)!)"
                            class="encode__block-head"
                        >
                            <h2 v-if="blockFor(step)?.label" class="encode__block-title">
                                {{ blockFor(step)?.label }}
                            </h2>
                            <p v-if="blockFor(step)?.description" class="encode__block-desc">
                                {{ blockFor(step)?.description }}
                            </p>
                            <p v-if="boundsHint(blockFor(step)!)" class="encode__block-note">
                                {{ boundsHint(blockFor(step)!) }}
                            </p>
                        </div>

                        <!-- Repeatable section: an add/remove-instance loop over the member fields (G2), now
                             driven by the store so per-instance relevance and the answer shape agree. -->
                        <template v-if="step.isRepeat && step.sectionKey !== null">
                            <p v-if="runtime.instanceCount(step.sectionKey) === 0" class="encode__empty">
                                Nothing added yet.
                            </p>

                            <ol v-else class="encode__instances">
                                <li v-for="(uid, index) in runtime.instanceUidsFor(step.sectionKey)" :key="uid">
                                    <fieldset class="encode__instance">
                                        <legend class="encode__instance-legend">
                                            {{ instanceLegend(blockFor(step)!, index) }}
                                        </legend>
                                        <div class="encode__fields">
                                            <template v-for="field in rowsOf(step)" :key="field.key">
                                                <div
                                                    v-if="runtime.instanceFieldRelevant(step.sectionKey, index, field.key)"
                                                    :id="anchorFor(runtime.instanceAddress(step.sectionKey, index, field.key))"
                                                >
                                                    <FieldInput
                                                        :field="field"
                                                        :model-value="instanceValue(step.sectionKey, index, field.key)"
                                                        :error="instanceError(step.sectionKey, index, field.key)"
                                                        :read-only="isEditing"
                                                        @update:model-value="setInstanceValue(step.sectionKey, index, field.key, $event)"
                                                    />
                                                </div>
                                            </template>
                                        </div>
                                        <div class="encode__instance-actions">
                                            <MdsButton
                                                type="button"
                                                variant="tertiary"
                                                size="sm"
                                                icon-left="trash"
                                                :aria-label="`Remove ${instanceLegend(blockFor(step)!, index)}`"
                                                @click="removeInstance(step.sectionKey, index)"
                                            >
                                                Remove
                                            </MdsButton>
                                        </div>
                                    </fieldset>
                                </li>
                            </ol>

                            <p v-if="countError(step.sectionKey)" class="encode__count-error" role="alert">
                                {{ countError(step.sectionKey) }}
                            </p>

                            <div class="encode__add">
                                <MdsButton
                                    type="button"
                                    variant="secondary"
                                    icon-left="plus"
                                    :disabled="!runtime.canAddInstance(step.sectionKey)"
                                    @click="addInstance(step.sectionKey)"
                                >
                                    Add {{ blockFor(step)?.label ?? 'entry' }}
                                </MdsButton>
                                <span v-if="!runtime.canAddInstance(step.sectionKey)" class="encode__max-note">
                                    Maximum of {{ blockFor(step)?.max_instances }} reached.
                                </span>
                            </div>
                        </template>

                        <!-- Flat step (or the synthetic lead block): relevant fields render directly. -->
                        <div v-else class="encode__fields">
                            <div v-for="field in rowsOf(step)" :key="field.key" :id="anchorFor(field.key)">
                                <FieldInput
                                    :field="field"
                                    :model-value="flatValue(field.key)"
                                    :error="fieldError(field.key)"
                                    :read-only="isEditing"
                                    @update:model-value="setFlatValue(field.key, $event)"
                                />
                            </div>
                        </div>
                    </div>
                </MdsCard>
            </template>

            <div class="encode__actions">
                <!-- The sticky footer's Cancel — the same `cancel_url` as the header's, which is the point. -->
                <Link v-if="cancel_url" :href="cancel_url" class="encode__cancel">Cancel</Link>
                <MdsButton
                    v-if="!form.single_page_mode && !runtime.isTerminal.value && !runtime.isFirstStep.value"
                    type="button"
                    variant="secondary"
                    icon-left="chevron-left"
                    @click="goPrev"
                >
                    Back
                </MdsButton>
                <!-- ⚠️ IN EDIT MODE, NEXT IS SECONDARY AND SAVE IS ALWAYS PRESENT.
                     Creating a response is a sequence — you fill step 1, then step 2, and Submit belongs at
                     the end. CORRECTING one is not: the editor came here to fix a known field, they land on
                     step 1 because edit mode restores no cursor, and with Save gated behind `isLastStep`
                     they would have to click Next through every remaining step to commit a one-character fix
                     on step 6 of 8 — with nothing on screen explaining why Save is missing. Save is
                     therefore rendered on every step, and Next stays available beside it for reading
                     through. -->
                <MdsButton
                    v-if="!form.single_page_mode && !runtime.isTerminal.value && !runtime.isLastStep.value"
                    type="button"
                    :variant="isEditing ? 'secondary' : 'primary'"
                    icon-right="chevron-right"
                    @click="goNext"
                >
                    Next
                </MdsButton>
                <MdsButton
                    v-if="isEditing || form.single_page_mode || runtime.isTerminal.value || runtime.isLastStep.value"
                    type="submit"
                    variant="primary"
                    icon-left="check"
                    :loading="submitting"
                    :disabled="!canSubmit"
                >
                    {{ isEditing ? 'Save changes' : 'Submit response' }}
                </MdsButton>
            </div>
        </form>
    </div>
</template>

<style scoped>
.encode {
    max-width: 720px;
}

/* `.encode__crumb` went with its markup in J2c — the hand-rolled crumb became `MdsBreadcrumb`, which brings
   its own styling. `.encode__cancel` keeps these rules: it is a Link styled as text, not a crumb. */
.encode__cancel {
    color: var(--mds-color-action-primary-fg);
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: none;
}

.encode__cancel:hover {
    text-decoration: underline;
}

.encode__cancel:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.encode__intro {
    margin: 0 0 var(--mds-space-6);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

/* Pruned-answer report (H21c) — border-accent rather than colour alone (WCAG 1.4.1), and a WARNING accent
   rather than a danger one: the submission succeeded, it is just narrower than what was typed. */
.encode__pruned {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-4);
    margin: 0 0 var(--mds-space-6);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    flex-wrap: wrap;
}

.encode__pruned-body {
    flex: 1 1 16rem;
    min-width: 0;
}

.encode__pruned-title {
    display: block;
    margin-bottom: var(--mds-space-1);
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-md-font-size);
}

.encode__pruned-lede {
    margin: 0 0 var(--mds-space-2);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.encode__pruned-list {
    margin: 0;
    padding-left: var(--mds-space-5);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Engine-degrade notice (H21c) — the same border-accent treatment as the schedule banner. */
.encode__degraded {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0 0 var(--mds-space-6);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.encode__degraded-title {
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-md-font-size);
}

.encode__degraded-body {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Autosave state + resume banner (I9b). */
.encode__autosave {
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
    align-self: center;
}

.encode__resume {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3) var(--mds-space-4);
    margin-bottom: var(--mds-space-4);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

.encode__resume-title {
    font-weight: var(--mds-font-weight-semibold);
}

.encode__resume-body {
    font-size: var(--mds-type-caption-font-size);
}

/* Edit banner (I9c). Shares the resume banner's shape — same job, different sentence — and takes the
   border-accent treatment for its warning variant rather than colour alone (WCAG 1.4.1, the rule the
   schedule banner below records). The accent is what distinguishes "this is a consequence" from "this is
   context" for a reader who cannot tell the two backgrounds apart. */
.encode__editing {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3) var(--mds-space-4);
    margin-bottom: var(--mds-space-4);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

/* The warning variant follows THIS PAGE'S existing banner convention rather than inventing a fourth one:
   `.encode__pruned`, `.encode__degraded` and `.encode__schedule-banner` all sit on `bg-surface` with a
   coloured left accent. An earlier draft tinted the whole background AND added a red rule beside an amber
   fill — two accent systems at once, on a page that already had one. */
.encode__editing--warning {
    background: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
    border: 1px solid var(--mds-color-border-default);
    border-left: 4px solid var(--mds-color-status-warning-fg);
}

.encode__editing--warning .encode__editing-title {
    color: var(--mds-color-text-heading);
}

.encode__editing--warning .encode__editing-body {
    color: var(--mds-color-text-secondary);
}

.encode__editing-title {
    font-weight: var(--mds-font-weight-semibold);
}

.encode__editing-body {
    font-size: var(--mds-type-caption-font-size);
}

/* Scheduled-form pre-warning (H12b) — an alert banner, border-accent (never color alone, WCAG 1.4.1). */
.encode__schedule-banner {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0 0 var(--mds-space-6);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-left: 4px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.encode__schedule-title {
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-md-font-size);
}

.encode__schedule-body {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Form-wide error summary (H21c, Doc #27 §5.5). */
.encode__summary {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-left: 4px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.encode__summary-title {
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-md-font-size);
}

.encode__summary-list {
    margin: 0;
    padding-left: var(--mds-space-5);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.encode__summary-link {
    padding: 0;
    border: 0;
    background: none;
    color: var(--mds-color-action-primary-fg);
    font: inherit;
    text-align: left;
    text-decoration: underline;
    cursor: pointer;
}

.encode__summary-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.encode__progress {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.encode__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__block {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__terminal {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.encode__block-head {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.encode__block-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.encode__block-desc {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.encode__block-note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__empty {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__instances {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.encode__instance {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    margin: 0;
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    min-width: 0;
}

.encode__instance-legend {
    padding: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.encode__instance-actions {
    display: flex;
    justify-content: flex-end;
}

.encode__count-error {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}

.encode__add {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
}

.encode__max-note {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--mds-space-4);
    flex-wrap: wrap;
}
</style>
