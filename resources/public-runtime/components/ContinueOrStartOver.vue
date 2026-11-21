<script setup lang="ts">
/**
 * "Continue where you left off" or "Start over" — M248 (`D118` A, `R-8881210a`, `R-d37a38f9`).
 *
 * Shown by `App.vue` INSTEAD of the form when this device holds answers an earlier visit left on this form — the tab was
 * closed, the link was opened again from a messaging app, or the respondent paused long enough for their visit to lapse.
 * It is asked BEFORE any answer appears, which is the whole of `D118`'s answer: on a shared phone the next person sees
 * this question, not the previous person's answers, and only Continue puts them on screen.
 *
 * Nothing is decided here: the two buttons emit, and `App.vue` adopts the row (Continue) or leaves it for the first save
 * to overwrite (Start over). Focus moves to the heading on arrival, as on the thank-you screen, so a screen reader is
 * told the page is asking something before anything else.
 */
import { computed, onMounted, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';
import { formatInstantInZone } from '../lib/schedule';

const props = defineProps<{
    title: string;
    /** When the earlier visit last saved (ISO). */
    savedAt: string;
    timeZone: string;
    locale: string;
}>();
const emit = defineEmits<{ continue: []; 'start-over': [] }>();

const heading = ref<HTMLElement | null>(null);
const busy = ref(false);
const savedWhen = computed(() => formatInstantInZone(props.savedAt, props.timeZone, props.locale));

function onContinue(): void {
    busy.value = true;
    emit('continue');
}

function onStartOver(): void {
    busy.value = true;
    emit('start-over');
}

onMounted(() => heading.value?.focus());
</script>

<template>
    <div class="answers-choice" data-continue-choice>
        <div class="answers-choice__card">
            <p class="answers-choice__form">{{ title }}</p>
            <h1 ref="heading" tabindex="-1" class="answers-choice__title">You started this form on this device</h1>
            <p class="answers-choice__text">
                Your answers were last saved on {{ savedWhen }}. Pick up where you left off, or start again with a blank form.
            </p>
            <div class="answers-choice__actions">
                <MdsButton variant="primary" :disabled="busy" data-continue @click="onContinue">Continue where you left off</MdsButton>
                <MdsButton variant="secondary" :disabled="busy" data-start-over @click="onStartOver">Start over</MdsButton>
            </div>
            <p class="answers-choice__note">On a shared phone or computer, choose Start over so you do not see someone else's answers.</p>
        </div>
    </div>
</template>

<style scoped>
/* The thank-you screen's frame (`ConfirmationScreen.vue`): a centred card on the canvas, `flex: 1` inside App's column. */
.answers-choice {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.answers-choice__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-4);
    max-width: 32rem;
    padding: var(--mds-space-8);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-xl, var(--mds-radius-lg));
    box-shadow: var(--mds-shadow-1);
}

.answers-choice__form {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.answers-choice__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-type-heading-2-font-weight);
    color: var(--mds-color-text-heading);
}

.answers-choice__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.answers-choice__text {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.answers-choice__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: var(--mds-space-2);
}

.answers-choice__note {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
