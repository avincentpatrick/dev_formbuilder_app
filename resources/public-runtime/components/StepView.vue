<script setup lang="ts">
/**
 * Multi-step / paginated presentation (UX §3.1): one section per step with Next/Back, the progress indicator,
 * and Submit only on the final step. A blocked Next (§4.2) reveals the step's inline errors + the summary
 * banner; a successful navigation announces the new step and moves focus to its heading (§10.1/§10.2). The
 * store handles dynamic recount + auto-advance off a now-irrelevant step; the `currentStepKey` watcher below
 * reacts to both a manual move and that automatic one.
 *
 * Increment H21b (Doc #27) adds the four things branching makes routine:
 *   §4.1 the empty graph as a TERMINAL state — no counter, an explicit panel, Submit as the single action;
 *   §5.5 Submit reports form-wide errors while a blocked Next stays step-scoped;
 *   §4.2/§3.1 the rescue off a vanished step announces the REASON, and a change in N is announced at all;
 *   §4.4 the step-level retained-answer notice, which no `FieldRow` can give for an off-screen step.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import ProgressIndicator from './ProgressIndicator.vue';
import RelevanceNote from './RelevanceNote.vue';
import SaveForLater from './SaveForLater.vue';
import SectionView from './SectionView.vue';
import SummaryBanner from './SummaryBanner.vue';
import { useAnnouncer, useRuntime, useSubmitFlow } from '../composables/context';
import type { ErroredItem } from '../composables/useFormRuntime';

const runtime = useRuntime();
const announcer = useAnnouncer();
const flow = useSubmitFlow();

const banner = ref<InstanceType<typeof SummaryBanner> | null>(null);
const bannerVisible = ref(false);
/**
 * Doc #27 §5.5. A blocked Next is a statement about THIS step, so its banner stays step-scoped. Submit is a
 * statement about the whole form, and scoping its banner the same way was a silent dead-end: `flow.submit()`
 * reports failure off form-wide validity, so when the failing field lived on another step the list was empty,
 * `v-if="bannerItems.length"` rendered nothing, and the announcer said "0 fields need your attention".
 */
const bannerScope = ref<'step' | 'form'>('step');

const currentStep = computed(() => runtime.currentStep.value);
const stepItems = computed(() => runtime.erroredItems.value.filter((item) => item.stepKey === currentStep.value?.key));
const bannerItems = computed(() => (bannerScope.value === 'form' ? runtime.erroredItems.value : stepItems.value));

/** Cross-step jump (§5.5): navigate first, then let the banner scroll to and focus the field it lands on. */
function beforeJump(item: ErroredItem): void {
    if (item.stepKey !== currentStep.value?.key) {
        runtime.goToStep(item.stepKey);
    }
}

// §4.4 — the step-level twin of `FieldRow`'s retain-and-restore note. Keyed by step so a second removal
// re-announces, matching the field level's "once per mount" rather than "once per session".
const retainedNote = ref<string | null>(null);
let retainedNoteTimer: ReturnType<typeof setTimeout> | null = null;

function dismissRetainedNote(): void {
    retainedNote.value = null;
    if (retainedNoteTimer !== null) {
        clearTimeout(retainedNoteTimer);
        retainedNoteTimer = null;
    }
}

function focusHeading(): void {
    const heading =
        document.querySelector<HTMLElement>('[data-section-heading]') ??
        document.querySelector<HTMLElement>('[data-runtime-main]');
    heading?.focus();
}

function stepLabel(): string {
    const index = Math.max(runtime.currentStepIndex.value, 0);
    const total = runtime.visibleSteps.value.length;
    const title = runtime.currentStep.value?.title;
    return `step ${index + 1} of ${total}${title ? `: ${title}` : ''}`;
}

function announceStep(): void {
    const label = stepLabel();
    announcer.announce(label.charAt(0).toUpperCase() + label.slice(1));
}

/**
 * Set when the step list itself moved the respondent, so the `currentStepKey` watcher below does not talk over
 * the reason with a bare "Step 2 of 3". §4.2 is explicit that the announcement must say WHY, not just where.
 */
let rescueAnnounced = false;

watch(
    () => runtime.currentStepKey.value,
    () => {
        bannerVisible.value = false;
        bannerScope.value = 'step';
        void nextTick(() => {
            focusHeading();
            if (rescueAnnounced) {
                rescueAnnounced = false;
                return;
            }
            announceStep();
        });
    },
);

watch(
    () => runtime.lastStepChange.value,
    (change) => {
        if (change === null) {
            return;
        }

        if (change.removedWithAnswers.length > 0) {
            const names = change.removedWithAnswers
                .map((key) => titleForRemoved(key, change.removed))
                .filter((name): name is string => name !== null);
            const text =
                names.length > 0
                    ? `Your answers in ${formatList(names)} won’t be included because ${names.length === 1 ? 'that step is' : 'those steps are'} no longer relevant. They’re saved in case ${names.length === 1 ? 'it applies' : 'they apply'} again.`
                    : 'Some of your answers won’t be included because their step is no longer relevant. They’re saved in case it applies again.';
            dismissRetainedNote();
            retainedNote.value = text;
            announcer.announce(text);
            retainedNoteTimer = setTimeout(dismissRetainedNote, 8000);
        }

        if (change.rescuedFrom !== null) {
            rescueAnnounced = true;
            void nextTick(() => {
                announcer.announce(
                    change.rescuedTo === null
                        ? 'The step you were on no longer applies, and there are no further questions. You can submit the answers you’ve given.'
                        : `The step you were on no longer applies. You’ve been moved to ${stepLabel()}.`,
                );
            });
            return;
        }

        // §3.1 — a step can appear or disappear BEHIND the respondent without moving them. The count changes
        // silently today, because the only announcement watches `currentStepKey`, which did not change.
        if (change.count !== change.previousCount && change.count > 0) {
            announcer.announce(`This form now has ${change.count} ${change.count === 1 ? 'step' : 'steps'}.`);
        }
    },
);

/** The removed step's title, read from the change record's own snapshot — it is gone from `visibleSteps`. */
function titleForRemoved(key: string, removed: string[]): string | null {
    if (!removed.includes(key)) {
        return null;
    }
    const section = runtime.renderModel.sections.find((s) => s.key === key);
    return section ? `“${runtime.sectionTitleFor(section)}”` : null;
}

function formatList(names: string[]): string {
    if (names.length === 1) {
        return names[0];
    }
    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

async function onNext(): Promise<void> {
    const result = runtime.attemptNext();
    if (!result.advanced) {
        bannerScope.value = 'step';
        bannerVisible.value = true;
        await nextTick();
        banner.value?.focus();
        announcer.announce(
            `${result.errorCount} ${result.errorCount === 1 ? 'field needs' : 'fields need'} your attention before continuing.`,
        );
    }
    // On success the currentStepKey watcher announces + focuses.
}

function onBack(): void {
    runtime.goPrev();
}

async function onSubmit(): Promise<void> {
    const outcome = await flow.submit();
    if (outcome === 'field-errors') {
        bannerScope.value = 'form';
        bannerVisible.value = true;
        await nextTick();
        banner.value?.focus();
        const count = bannerItems.value.length;
        announcer.announce(
            `${count} ${count === 1 ? 'field needs' : 'fields need'} your attention before submitting.`,
        );
    }
}

function onFormSubmit(): void {
    if (runtime.isTerminal.value || runtime.isLastStep.value) {
        void onSubmit();
    } else {
        void onNext();
    }
}
</script>

<template>
    <form class="step-view" novalidate @submit.prevent="onFormSubmit">
        <ProgressIndicator />
        <SummaryBanner
            v-if="bannerVisible && bannerItems.length"
            ref="banner"
            :items="bannerItems"
            :before-jump="beforeJump"
        />
        <Transition name="step-note">
            <RelevanceNote v-if="retainedNote" label="" :text="retainedNote" @dismiss="dismissRetainedNote" />
        </Transition>
        <!--
            Doc #27 §4.1 — the empty graph is a specified terminal state, not an error. Reached easily under
            branching (a router form whose every section is gated on a URL-prefilled hidden field). The heading
            carries `data-section-heading` so the existing focus walk still lands somewhere real.
        -->
        <div v-if="runtime.isTerminal.value" class="step-view__terminal">
            <h2 class="step-view__terminal-title" tabindex="-1" data-section-heading>Nothing further to complete</h2>
            <p class="step-view__terminal-body">
                Based on your answers there are no more questions for you. You can submit the answers you’ve
                already given.
            </p>
        </div>
        <SectionView v-else-if="currentStep" :key="currentStep.key" :step="currentStep" />
        <div class="step-view__actions">
            <MdsButton
                v-if="!runtime.isTerminal.value && !runtime.isFirstStep.value"
                type="button"
                variant="secondary"
                @click="onBack"
            >
                Back
            </MdsButton>
            <!-- Self-hides unless the form offers save-and-resume (Increment H10); shown on every step. -->
            <SaveForLater />
            <span class="step-view__spacer" />
            <MdsButton
                v-if="!runtime.isTerminal.value && !runtime.isLastStep.value"
                type="button"
                @click="onNext"
            >
                Next
            </MdsButton>
            <MdsButton v-else type="submit" :loading="flow.submitting.value">
                {{ runtime.isTerminal.value ? 'Submit my answers' : 'Submit' }}
            </MdsButton>
        </div>
    </form>
</template>

<style scoped>
.step-view {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.step-view__terminal {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-6);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

/* Matches SectionView's `h2`, so the terminal panel reads as the step it stands in for. */
.step-view__terminal-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.step-view__terminal-title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.step-view__terminal-body {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

/* Matches the field-level collapse (FieldRow.vue), including the reduced-motion opt-out. */
.step-note-enter-active,
.step-note-leave-active {
    transition:
        opacity var(--mds-duration-base) var(--mds-ease-standard),
        transform var(--mds-duration-base) var(--mds-ease-standard);
}
.step-note-enter-from,
.step-note-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .step-note-enter-active,
    .step-note-leave-active {
        transition: none;
    }
}

.step-view__actions {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    padding-top: var(--mds-space-2);
}

.step-view__spacer {
    flex: 1;
}
</style>
