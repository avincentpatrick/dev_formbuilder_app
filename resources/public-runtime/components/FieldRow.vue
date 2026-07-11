<script setup lang="ts">
/**
 * One field slot. Owns the relevance show/hide (with a smooth transition + retain-and-restore note, UX §4.1),
 * the touched-on-blur wiring for the hybrid validation UX (§4.2 — `focusout` bubbles from the inner control),
 * the one-time "no longer relevant" note, focus rescue when a focused field is hidden (§10.2), and the
 * jump-anchor id the summary banner scrolls to. The value itself lives in the store, so unmounting a hidden
 * control never loses the answer.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { Coercion, type EngineValue } from '../engine';
import FieldControl from './FieldControl.vue';
import RelevanceNote from './RelevanceNote.vue';
import { useAnnouncer, useRuntime } from '../composables/context';
import type { RenderField } from '../lib/types';

const props = defineProps<{ field: RenderField }>();
const runtime = useRuntime();
const announcer = useAnnouncer();

const rootEl = ref<HTMLElement | null>(null);
const showNote = ref(false);
let noteShownOnce = false;
let noteTimer: ReturnType<typeof setTimeout> | null = null;

const relevant = computed(() => runtime.fieldRelevance.value[props.field.key] === true);
const anchorId = computed(() => `field-${props.field.key}`);

function dismissNote(): void {
    showNote.value = false;
    if (noteTimer !== null) {
        clearTimeout(noteTimer);
        noteTimer = null;
    }
}

watch(relevant, (isRelevant, wasRelevant) => {
    if (wasRelevant && !isRelevant) {
        // Became irrelevant. Rescue focus if it was inside this row, then (once) explain the retained value.
        const hadFocus = rootEl.value?.contains(document.activeElement) ?? false;
        if (hadFocus) {
            void nextTick(rescueFocus);
        }
        // A FieldRow only ever renders a flat field, so its stored value is a scalar EngineValue (never a
        // repeat-instance array — those render through RepeatGroup/InstanceField).
        const hasValue = !Coercion.isEmpty((runtime.answers[props.field.key] ?? null) as EngineValue);
        if (hasValue && !noteShownOnce) {
            noteShownOnce = true;
            showNote.value = true;
            announcer.announce(
                `Your answer to ${runtime.labelFor(props.field)} won’t be included because it’s no longer relevant.`,
            );
            noteTimer = setTimeout(dismissNote, 6000);
        }
    } else if (!wasRelevant && isRelevant) {
        // Newly shown — announce but do NOT steal focus (§10.2).
        announcer.announce(`New question: ${runtime.labelFor(props.field)}`);
    }
});

function rescueFocus(): void {
    const section = rootEl.value?.closest<HTMLElement>('[data-section]');
    const heading = section?.querySelector<HTMLElement>('[data-section-heading]');
    const fallback = heading ?? document.querySelector<HTMLElement>('[data-runtime-main]');
    fallback?.focus();
}

function onFocusout(): void {
    runtime.markTouched(props.field.key);
}
</script>

<template>
    <div :id="anchorId" ref="rootEl" class="field-row" data-field-row @focusout="onFocusout">
        <Transition name="field-collapse">
            <FieldControl v-if="relevant" :field="field" />
        </Transition>
        <Transition name="field-collapse">
            <RelevanceNote v-if="showNote" :label="runtime.labelFor(field)" @dismiss="dismissNote" />
        </Transition>
    </div>
</template>

<style scoped>
.field-row {
    display: block;
}

/* Smooth expand/collapse so surrounding content doesn't visibly jump (UX §4.1). */
.field-collapse-enter-active,
.field-collapse-leave-active {
    transition:
        opacity var(--mds-duration-base) var(--mds-ease-standard),
        transform var(--mds-duration-base) var(--mds-ease-standard);
}
.field-collapse-enter-from,
.field-collapse-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .field-collapse-enter-active,
    .field-collapse-leave-active {
        transition: none;
    }
}
</style>
