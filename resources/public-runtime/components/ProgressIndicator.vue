<script setup lang="ts">
/**
 * Multi-step progress (UX §3.2). Shows "Step X of N" + the current section title, where N is DYNAMIC — it
 * recomputes from the store's `visibleSteps` as sections become (ir)relevant, so a respondent never sees a
 * "skipped" step. Already-VISITED steps are clickable to revise an earlier answer.
 *
 * Increment H21b, Doc #27 §4.1 — the whole nav is suppressed at zero visible steps. Without that guard the
 * `Math.max(index, 0)` clamp below turns `findIndex`'s -1 into 0 and prints "Step 1 of 0" over a form with
 * nothing in it; §4.1 specifies suppression plus StepView's terminal panel instead.
 *
 * §3.1 — "done" comes from the store's VISITED SET, not from `index < currentIndex`. Under branching a step
 * can become relevant BEHIND the respondent, and an index comparison would hand a step they have never seen a
 * clickable "done" dot the moment it appeared.
 *
 * M249 (`progress-chapters`, `D233` answer 4) — WITH CHAPTERS, the bar shows "3 of 10 · {chapter}", one mark per
 * visible chapter (a visited one is a button to its first visited page), then "Page 2 of 3: {page}" and the current
 * chapter's own page dots, exactly as the step dots below but scoped to the chapter. A form whose sections start no
 * chapter (`runtime.chapters` null) draws the step dots as before. Either way a page is named by
 * `progressTextFor()`: its progress label, or its title piped as the heading is (`progress-title-unpiped`).
 */
import { computed } from 'vue';
import { useRuntime } from '../composables/context';
import type { RuntimeStep } from '../composables/useFormRuntime';
import { chapterName, type Chapter } from '../lib/chapters';

const runtime = useRuntime();

const steps = computed(() => runtime.visibleSteps.value);
const currentIndex = computed(() => Math.max(runtime.currentStepIndex.value, 0));
// Increment M124 — a page after a section's first reads "(continued)", so two pages never share one name.
const currentTitle = computed(() => (runtime.currentStep.value ? runtime.progressTextFor(runtime.currentStep.value) : null));

function textOf(step: RuntimeStep): string | null {
    return runtime.progressTextFor(step);
}

// ── M249: chapters ──────────────────────────────────────────────────────────────────────────────
const chapters = computed(() => runtime.chapters.value);
const chapterIndex = computed(() => {
    const key = runtime.currentStep.value?.key;
    const found = (chapters.value ?? []).findIndex((chapter) => chapter.steps.some((step) => step.key === key));

    return Math.max(found, 0);
});
const currentChapter = computed(() => chapters.value?.[chapterIndex.value] ?? null);
const pageIndex = computed(() => {
    const key = runtime.currentStep.value?.key;

    return Math.max(currentChapter.value?.steps.findIndex((step) => step.key === key) ?? 0, 0);
});

function nameOf(chapter: Chapter<RuntimeStep>): string {
    return chapterName(chapter, textOf);
}

/** The first page of a chapter the respondent has been on, or null when they have been on none of it. */
function firstVisited(chapter: Chapter<RuntimeStep>): RuntimeStep | null {
    return chapter.steps.find((step) => runtime.hasVisited(step.key)) ?? null;
}

function goToChapter(chapter: Chapter<RuntimeStep>): void {
    const step = firstVisited(chapter);
    if (step !== null) {
        runtime.goToStep(step.key);
    }
}
</script>

<template>
    <nav v-if="steps.length > 0 && chapters !== null && currentChapter !== null" class="progress" aria-label="Form progress" data-progress-chapters>
        <p class="progress__label" data-progress-chapter>{{ chapterIndex + 1 }} of {{ chapters.length }} · {{ nameOf(currentChapter) }}</p>
        <ol class="progress__chapters" aria-label="Chapters">
            <li v-for="(chapter, index) in chapters" :key="chapter.key" class="progress__chapter-item">
                <button
                    v-if="index !== chapterIndex && firstVisited(chapter) !== null"
                    type="button"
                    class="progress__chapter progress__chapter--done"
                    :aria-label="`Go to ${nameOf(chapter)}`"
                    @click="goToChapter(chapter)"
                ></button>
                <span
                    v-else
                    class="progress__chapter"
                    :class="{ 'progress__chapter--current': index === chapterIndex }"
                    :aria-current="index === chapterIndex ? 'true' : undefined"
                    ><span class="progress__hidden">{{ nameOf(chapter) }}</span></span
                >
            </li>
        </ol>
        <p class="progress__page" data-progress-page>
            Page {{ pageIndex + 1 }} of {{ currentChapter.steps.length }}<span v-if="currentTitle">: {{ currentTitle }}</span>
        </p>
        <ol class="progress__steps" :aria-label="`Pages in ${nameOf(currentChapter)}`">
            <li v-for="(step, index) in currentChapter.steps" :key="step.key" class="progress__step">
                <button
                    v-if="index !== pageIndex && runtime.hasVisited(step.key)"
                    type="button"
                    class="progress__dot progress__dot--done"
                    :aria-label="`Go to page ${index + 1}${textOf(step) ? `: ${textOf(step)}` : ''}`"
                    @click="runtime.goToStep(step.key)"
                >
                    {{ index + 1 }}
                </button>
                <span
                    v-else
                    class="progress__dot"
                    :class="{ 'progress__dot--current': index === pageIndex }"
                    :aria-current="index === pageIndex ? 'step' : undefined"
                >
                    {{ index + 1 }}
                </span>
            </li>
        </ol>
    </nav>
    <nav v-else-if="steps.length > 0" class="progress" aria-label="Form progress">
        <p class="progress__label">
            Step {{ currentIndex + 1 }} of {{ steps.length }}<span v-if="currentTitle">: {{ currentTitle }}</span>
        </p>
        <ol class="progress__steps">
            <li v-for="(step, index) in steps" :key="step.key" class="progress__step">
                <button
                    v-if="index !== currentIndex && runtime.hasVisited(step.key)"
                    type="button"
                    class="progress__dot progress__dot--done"
                    :aria-label="`Go to step ${index + 1}${textOf(step) ? `: ${textOf(step)}` : ''}`"
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
    /* M249: the containing block for the chapter marks' hidden names (the visually-hidden idiom below). */
    position: relative;
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

/* ── M249: chapters ─────────────────────────────────────────────────────────────────────────────── */
.progress__page {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/*
 * One mark per chapter: a 24px-tall target (WCAG 2.5.8) holding a thin bar, so ten fit a 320px phone in one row and more
 * wrap rather than shrink below the target. Done and current differ in THICKNESS as well as colour (WCAG 1.4.1).
 */
.progress__chapters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
}

.progress__chapter-item {
    display: flex;
    flex: 1 1 1.5rem;
    min-width: 1.5rem;
}

.progress__chapter {
    display: flex;
    align-items: center;
    width: 100%;
    height: 1.5rem;
    padding: 0;
    border: 0;
    background: transparent;
}

.progress__chapter::before {
    content: '';
    display: block;
    width: 100%;
    height: 0.25rem;
    border-radius: var(--mds-radius-full);
    /* A mark the respondent must tell from the page around it: the secondary text colour clears 3:1 (WCAG 1.4.11). */
    background-color: var(--mds-color-text-secondary);
}

.progress__chapter--done::before {
    height: 0.375rem;
    background-color: var(--mds-color-action-primary-bg);
}

.progress__chapter--current::before {
    height: 0.75rem;
    background-color: var(--mds-color-action-primary-bg);
}

button.progress__chapter {
    cursor: pointer;
}

button.progress__chapter:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.progress__hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
