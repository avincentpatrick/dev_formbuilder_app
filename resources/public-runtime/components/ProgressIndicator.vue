<script setup lang="ts">
/**
 * Multi-step progress (UX §3.2). Shows "Step X of N" + the current section title, where N is DYNAMIC — it
 * recomputes from the store's `visibleSteps` as sections become (ir)relevant, so a respondent never sees a
 * "skipped" step. Already-completed steps are clickable to revise an earlier answer.
 */
import { computed } from 'vue';
import { useRuntime } from '../composables/context';

const runtime = useRuntime();

const steps = computed(() => runtime.visibleSteps.value);
const currentIndex = computed(() => Math.max(runtime.currentStepIndex.value, 0));
const currentTitle = computed(() => runtime.currentStep.value?.title ?? null);
</script>

<template>
    <nav class="progress" aria-label="Form progress">
        <p class="progress__label">
            Step {{ currentIndex + 1 }} of {{ steps.length }}<span v-if="currentTitle">: {{ currentTitle }}</span>
        </p>
        <ol class="progress__steps">
            <li v-for="(step, index) in steps" :key="step.key" class="progress__step">
                <button
                    v-if="index < currentIndex"
                    type="button"
                    class="progress__dot progress__dot--done"
                    :aria-label="`Go to step ${index + 1}${step.title ? `: ${step.title}` : ''}`"
                    @click="runtime.goToStep(step.key)"
                >
                    {{ index + 1 }}
                </button>
                <span
                    v-else
                    class="progress__dot"
                    :class="{ 'progress__dot--current': index === currentIndex }"
                    :aria-current="index === currentIndex ? 'step' : undefined"
                >
                    {{ index + 1 }}
                </span>
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.progress {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.progress__label {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.progress__steps {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.progress__dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
}

button.progress__dot {
    cursor: pointer;
}

.progress__dot--done {
    border-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-action-primary-fg);
}

.progress__dot--current {
    border-color: var(--mds-color-action-primary-bg);
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
}

button.progress__dot:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
