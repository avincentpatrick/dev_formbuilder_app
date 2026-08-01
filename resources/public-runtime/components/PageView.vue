<script setup lang="ts">
/**
 * Single-page presentation (UX §3.1): every relevant section rendered in one continuous scroll with one
 * persistent Submit and the submit-time summary banner (§4.2). No progress indicator (scroll position is the
 * progress), so there is no count to keep honest here.
 *
 * Increment H21b closes Doc #27 §4.4. Until now this was a bare `v-for` over the visible steps with no
 * transition, no focus rescue and no announcement — while the FIELD level had all three. So when an answer
 * flipped a whole section irrelevant while the respondent was typing inside it, hundreds of pixels vanished
 * synchronously under the scroll position, `document.activeElement` reset to `<body>`, and nothing was said.
 * That was a live accessibility defect before branching and becomes central under it.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import RelevanceNote from './RelevanceNote.vue';
import SaveForLater from './SaveForLater.vue';
import SectionView from './SectionView.vue';
import SummaryBanner from './SummaryBanner.vue';
import { useAnnouncer, useRuntime, useSubmitFlow } from '../composables/context';

const runtime = useRuntime();
const announcer = useAnnouncer();
const flow = useSubmitFlow();

const banner = ref<InstanceType<typeof SummaryBanner> | null>(null);
const bannerVisible = ref(false);

const bannerItems = computed(() => runtime.erroredItems.value);

/**
 * The section key that currently holds focus, tracked on the way IN. It cannot be read on the way out: by the
 * time a removal is observable the element is unmounted, so `closest('[data-section]')` has nothing to find.
 */
const focusedSectionKey = ref<string | null>(null);

function onFocusin(event: FocusEvent): void {
    const target = event.target as HTMLElement | null;
    focusedSectionKey.value = target?.closest<HTMLElement>('[data-section]')?.dataset.sectionKey ?? null;
}

/**
 * §4.4 / §10.2 — the same walk `FieldRow.rescueFocus()` performs, one level up: land on the nearest PRECEDING
 * surviving section heading, falling back to the runtime's main landmark. Focus is only moved when it was
 * actually inside the removed section; a removal elsewhere on the page must not steal the caret.
 */
function rescueFocus(removedKey: string): void {
    const visible = new Set(runtime.visibleSteps.value.map((s) => s.key));
    const authored = runtime.renderModel.sections.map((s) => s.key);
    let targetKey: string | null = null;
    for (let i = authored.indexOf(removedKey) - 1; i >= 0; i--) {
        if (visible.has(authored[i])) {
            targetKey = authored[i];
            break;
        }
    }
    const section =
        targetKey === null
            ? null
            : Array.from(document.querySelectorAll<HTMLElement>('[data-section]')).find(
                  (el) => el.dataset.sectionKey === targetKey,
              );
    const heading = section?.querySelector<HTMLElement>('[data-section-heading]') ?? null;
    (heading ?? document.querySelector<HTMLElement>('[data-runtime-main]'))?.focus();
}

function titleFor(key: string): string | null {
    const section = runtime.renderModel.sections.find((s) => s.key === key);
    return section ? runtime.sectionTitleFor(section) : null;
}

// §4.4's section-level retained-answer note — the same primitive the field level uses, one scope up.
const retainedNote = ref<string | null>(null);
let retainedNoteTimer: ReturnType<typeof setTimeout> | null = null;

function dismissRetainedNote(): void {
    retainedNote.value = null;
    if (retainedNoteTimer !== null) {
        clearTimeout(retainedNoteTimer);
        retainedNoteTimer = null;
    }
}

watch(
    () => runtime.lastStepChange.value,
    (change) => {
        if (change === null) {
            return;
        }

        const lostFocus = focusedSectionKey.value !== null && change.removed.includes(focusedSectionKey.value);
        if (lostFocus) {
            const removedKey = focusedSectionKey.value as string;
            focusedSectionKey.value = null;
            void nextTick(() => rescueFocus(removedKey));
        }

        for (const key of change.removed) {
            const title = titleFor(key);
            if (title !== null) {
                announcer.announce(`The “${title}” section no longer applies and has been removed.`);
            }
        }
        // Newly shown sections announce but never steal focus (§10.2, matching `FieldRow`).
        for (const key of change.added) {
            const title = titleFor(key);
            if (title !== null) {
                announcer.announce(`New section: ${title}`);
            }
        }

        if (change.removedWithAnswers.length > 0) {
            const names = change.removedWithAnswers
                .map((key) => titleFor(key))
                .filter((name): name is string => name !== null)
                .map((name) => `“${name}”`);
            const text =
                names.length > 0
                    ? `Your answers in ${names.join(', ')} won’t be included because ${names.length === 1 ? 'that section is' : 'those sections are'} no longer relevant. They’re saved in case ${names.length === 1 ? 'it applies' : 'they apply'} again.`
                    : 'Some of your answers won’t be included because their section is no longer relevant. They’re saved in case it applies again.';
            dismissRetainedNote();
            retainedNote.value = text;
            announcer.announce(text);
            retainedNoteTimer = setTimeout(dismissRetainedNote, 8000);
        }
    },
);

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
</script>

<template>
    <!-- `focusin` bubbles, so one handler on the form catches every control; focus outside any section
         correctly clears the tracked key rather than leaving a stale one behind. -->
    <form class="page-view" novalidate @submit.prevent="onSubmit" @focusin="onFocusin">
        <SummaryBanner v-if="bannerVisible && bannerItems.length" ref="banner" :items="bannerItems" />
        <Transition name="section-collapse">
            <RelevanceNote v-if="retainedNote" label="" :text="retainedNote" @dismiss="dismissRetainedNote" />
        </Transition>
        <TransitionGroup tag="div" name="section-collapse" class="page-view__sections">
            <SectionView v-for="step in runtime.visibleSteps.value" :key="step.key" :step="step" />
        </TransitionGroup>
        <div class="page-view__actions">
            <!-- Self-hides unless the form offers save-and-resume (Increment H10). -->
            <SaveForLater />
            <MdsButton type="submit" size="lg" :loading="flow.submitting.value">Submit</MdsButton>
        </div>
    </form>
</template>

<style scoped>
.page-view {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.page-view__sections {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
    /* Keep the scroll position pinned to what the respondent is reading when a section above it collapses. */
    overflow-anchor: auto;
}

.page-view__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-3);
    padding-top: var(--mds-space-2);
}

/* The field-level collapse (FieldRow.vue), lifted to the section list, reduced-motion opt-out included.
   Deliberately WITHOUT the usual `position: absolute` on leave: a section is hundreds of pixels tall, and
   taking it out of flow mid-transition would overlay the content below it. Keeping it in flow means the gap
   closes after the fade rather than during — less balletic, but nothing ever draws on top of a question. */
.section-collapse-enter-active,
.section-collapse-leave-active {
    transition:
        opacity var(--mds-duration-base) var(--mds-ease-standard),
        transform var(--mds-duration-base) var(--mds-ease-standard);
}
.section-collapse-enter-from,
.section-collapse-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .section-collapse-enter-active,
    .section-collapse-leave-active {
        transition: none;
    }
}
</style>
