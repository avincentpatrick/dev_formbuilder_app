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
import { MdsButton, MdsCard } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
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
}>();

const page = usePage();

// ── The runtime store ────────────────────────────────────────────────────────────────────────────────────
// `search: ''` is what keeps H7's URL prefill off this channel: a keyer is the SOURCE of a `url`-sourced
// value, not a recipient of one, so reading the tenant app's query string here would silently prefill from an
// unrelated parameter. `buildPrefill` still seeds `fixed` literals, which is what lets a label pipe them.
//
// `now` comes from the SERVER (`version.now`), not from `isoClock(new Date())` as the guest does — see
// `EncodeFormPresenter::clock()`. Same clock in, same step list out.
const runtime = createFormRuntime(props as unknown as SchemaResponse, {
    now: props.version.now,
    initialLocale: props.form.default_locale,
    search: '',
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
            return {
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

function setInstanceValue(sectionKey: string, index: number, fieldKey: string, value: AnswerValue): void {
    runtime.setInstanceAnswer(sectionKey, index, fieldKey, value as never);
    runtime.markInstanceTouched(sectionKey, index, fieldKey);
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
 * Jump to the field an error summary entry addresses. The step has to change FIRST and the DOM has to settle
 * before the anchor exists — in stepped mode the field is on an unmounted step, which is the trap H21b's
 * `SummaryBanner` `beforeJump` prop was added for.
 */
async function jumpTo(item: { address: string; stepKey: string }): Promise<void> {
    if (!props.form.single_page_mode && item.stepKey !== runtime.currentStepKey.value) {
        runtime.goToStep(item.stepKey);
        await nextTick();
    }
    const target = document.getElementById(`field-${item.address}`) ?? document.getElementById(item.address);
    target?.scrollIntoView({ block: 'center' });
    target?.focus();
}

// ── Submit ───────────────────────────────────────────────────────────────────────────────────────────────
const submitting = ref(false);

function submit(): void {
    // Increment H12b — never POST a submission the schedule guard will 403 (the button is disabled too).
    if (!isOpen.value) {
        return;
    }
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
        { answers },
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
</script>

<template>
    <div class="encode">
        <Head :title="`Encode — ${form.title}`" />

        <PageHeader title="New submission" icon="submissions">
            <template #breadcrumbs>
                <Link href="/forms" class="encode__crumb">← Forms</Link>
            </template>
            <template #actions>
                <Link href="/forms" class="encode__cancel">Cancel</Link>
            </template>
        </PageHeader>

        <p class="encode__intro">
            Encoding a response for <strong>{{ form.title }}</strong> (v{{ version.version_number }}).
        </p>

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
                        <FieldInput
                            v-for="field in referenceRows"
                            :key="field.key"
                            :field="field"
                            :model-value="flatValue(field.key)"
                            :error="fieldError(field.key)"
                            @update:model-value="setFlatValue(field.key, $event)"
                        />
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
                                                <FieldInput
                                                    v-if="runtime.instanceFieldRelevant(step.sectionKey, index, field.key)"
                                                    :field="field"
                                                    :model-value="instanceValue(step.sectionKey, index, field.key)"
                                                    :error="instanceError(step.sectionKey, index, field.key)"
                                                    @update:model-value="setInstanceValue(step.sectionKey, index, field.key, $event)"
                                                />
                                            </template>
                                        </div>
                                        <div class="encode__instance-actions">
                                            <MdsButton
                                                type="button"
                                                variant="tertiary"
                                                size="sm"
                                                icon-left="trash"
                                                :aria-label="`Remove ${instanceLegend(blockFor(step)!, index)}`"
                                                @click="runtime.removeInstance(step.sectionKey, index)"
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
                                    @click="runtime.addInstance(step.sectionKey)"
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
                            <FieldInput
                                v-for="field in rowsOf(step)"
                                :key="field.key"
                                :field="field"
                                :model-value="flatValue(field.key)"
                                :error="fieldError(field.key)"
                                @update:model-value="setFlatValue(field.key, $event)"
                            />
                        </div>
                    </div>
                </MdsCard>
            </template>

            <div class="encode__actions">
                <Link href="/forms" class="encode__cancel">Cancel</Link>
                <MdsButton
                    v-if="!form.single_page_mode && !runtime.isTerminal.value && !runtime.isFirstStep.value"
                    type="button"
                    variant="secondary"
                    icon-left="chevron-left"
                    @click="goPrev"
                >
                    Back
                </MdsButton>
                <MdsButton
                    v-if="!form.single_page_mode && !runtime.isTerminal.value && !runtime.isLastStep.value"
                    type="button"
                    variant="primary"
                    icon-right="chevron-right"
                    @click="goNext"
                >
                    Next
                </MdsButton>
                <MdsButton
                    v-else
                    type="submit"
                    variant="primary"
                    icon-left="check"
                    :loading="submitting"
                    :disabled="!isOpen"
                >
                    Submit response
                </MdsButton>
            </div>
        </form>
    </div>
</template>

<style scoped>
.encode {
    max-width: 720px;
}

.encode__crumb,
.encode__cancel {
    color: var(--mds-color-action-primary-fg);
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: none;
}

.encode__crumb:hover,
.encode__cancel:hover {
    text-decoration: underline;
}

.encode__crumb:focus-visible,
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
