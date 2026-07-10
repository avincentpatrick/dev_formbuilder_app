<script setup lang="ts">
/**
 * Multi-step / paginated presentation (UX §3.1): one section per step with Next/Back, the progress indicator,
 * and Submit only on the final step. A blocked Next (§4.2) reveals the step's inline errors + the summary
 * banner; a successful navigation announces the new step and moves focus to its heading (§10.1/§10.2). The
 * store handles dynamic recount + auto-advance off a now-irrelevant step; the `currentStepKey` watcher below
 * reacts to both a manual move and that automatic one.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import ProgressIndicator from './ProgressIndicator.vue';
import SectionView from './SectionView.vue';
import SummaryBanner from './SummaryBanner.vue';
import { useAnnouncer, useRuntime, useSubmitFlow } from '../composables/context';

const runtime = useRuntime();
const announcer = useAnnouncer();
const flow = useSubmitFlow();

const banner = ref<InstanceType<typeof SummaryBanner> | null>(null);
const bannerVisible = ref(false);

const currentStep = computed(() => runtime.currentStep.value);
const stepFieldKeys = computed(() => new Set(currentStep.value?.fieldKeys ?? []));
const bannerItems = computed(() =>
    runtime.erroredFields.value
        .filter((f) => stepFieldKeys.value.has(f.key))
        .map((f) => ({ key: f.key, label: runtime.labelFor(f) })),
);

function focusHeading(): void {
    const heading =
        document.querySelector<HTMLElement>('[data-section-heading]') ??
        document.querySelector<HTMLElement>('[data-runtime-main]');
    heading?.focus();
}

function announceStep(): void {
    const index = Math.max(runtime.currentStepIndex.value, 0);
    const total = runtime.visibleSteps.value.length;
    const title = runtime.currentStep.value?.title;
    announcer.announce(`Step ${index + 1} of ${total}${title ? `: ${title}` : ''}`);
}

watch(
    () => runtime.currentStepKey.value,
    () => {
        bannerVisible.value = false;
        void nextTick(() => {
            focusHeading();
            announceStep();
        });
    },
);

async function onNext(): Promise<void> {
    const result = runtime.attemptNext();
    if (!result.advanced) {
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
        bannerVisible.value = true;
        await nextTick();
        banner.value?.focus();
        announcer.announce(
            `${bannerItems.value.length} ${bannerItems.value.length === 1 ? 'field needs' : 'fields need'} your attention before submitting.`,
        );
    }
}

function onFormSubmit(): void {
    if (runtime.isLastStep.value) {
        void onSubmit();
    } else {
        void onNext();
    }
}
</script>

<template>
    <form class="step-view" novalidate @submit.prevent="onFormSubmit">
        <ProgressIndicator />
        <SummaryBanner v-if="bannerVisible && bannerItems.length" ref="banner" :items="bannerItems" />
        <SectionView v-if="currentStep" :key="currentStep.key" :step="currentStep" />
        <div class="step-view__actions">
            <MdsButton v-if="!runtime.isFirstStep.value" type="button" variant="secondary" @click="onBack">
                Back
            </MdsButton>
            <span class="step-view__spacer" />
            <MdsButton v-if="!runtime.isLastStep.value" type="button" @click="onNext">Next</MdsButton>
            <MdsButton v-else type="submit" :loading="flow.submitting.value">Submit</MdsButton>
        </div>
    </form>
</template>

<style scoped>
.step-view {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
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
