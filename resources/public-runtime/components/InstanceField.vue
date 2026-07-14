<script setup lang="ts">
/**
 * One repeat-instance field slot (Increment G2) — the per-instance analogue of `FieldRow` + `FieldControl`.
 * It adapts a repeatable-section member field + the store's instance-addressed value/relevance/error onto the
 * SHARED F4b `FieldInput.vue` (the same control set the flat runtime + manual encoding use — UX §1 parity).
 *
 * Owns the same per-slot concerns as `FieldRow`, but addressed by `(sectionKey, index, fieldKey)`: the
 * relevance show/hide with retain-and-restore (the value stays in the store while hidden and reappears intact),
 * the touched-on-blur wiring for the hybrid validation UX, focus rescue when a focused field is hidden, and the
 * `field-<address>` jump anchor the summary banner scrolls to.
 */
import { computed, nextTick, ref, watch } from 'vue';
import FieldInput, { type AnswerValue, type EncodeField, type GeoEnvelope, type MediaAttachmentRef } from '@/components/submissions/FieldInput.vue';
import { resolveCascade, resolveOptional, resolveText } from '../lib/schema-mapping';
import { useAnnouncer, useRuntime } from '../composables/context';
import type { RenderField } from '../lib/types';

const props = defineProps<{ sectionKey: string; index: number; field: RenderField }>();
const runtime = useRuntime();
const announcer = useAnnouncer();

const rootEl = ref<HTMLElement | null>(null);

const relevant = computed(() =>
    runtime.instanceFieldRelevant(props.sectionKey, props.index, props.field.key),
);
const anchorId = computed(() => `field-${runtime.instanceAddress(props.sectionKey, props.index, props.field.key)}`);
const marker = computed(() => runtime.instanceRequiredMarkerFor(props.sectionKey, props.index, props.field));

const encodeField = computed<EncodeField>(() => ({
    key: props.field.key,
    field_type: props.field.fieldType,
    label: runtime.labelFor(props.field),
    hint: resolveOptional(props.field.hint, props.field.hintTranslations, runtime.locale.value),
    placeholder: props.field.placeholder,
    required: marker.value === 'required',
    options: props.field.options.map((o) => ({
        value: o.value,
        label: resolveText(o.label, o.labelTranslations, runtime.locale.value),
    })),
    cascade: resolveCascade(props.field.cascade, runtime.locale.value),
    supported: props.field.supported,
}));

const value = computed<AnswerValue>(() => {
    const current = runtime.instanceValue(props.sectionKey, props.index, props.field.key);
    if (current === undefined || current === null) {
        return null;
    }
    if (Array.isArray(current)) {
        return current.map(String);
    }
    return current;
});

const error = computed(() => runtime.instanceErrorFor(props.sectionKey, props.index, props.field.key));

watch(relevant, (isRelevant, wasRelevant) => {
    if (wasRelevant && !isRelevant) {
        const hadFocus = rootEl.value?.contains(document.activeElement) ?? false;
        if (hadFocus) {
            void nextTick(rescueFocus);
        }
    } else if (!wasRelevant && isRelevant) {
        announcer.announce(`New question: ${runtime.labelFor(props.field)}`);
    }
});

function rescueFocus(): void {
    const instance = rootEl.value?.closest<HTMLElement>('[data-repeat-instance]');
    const legend = instance?.querySelector<HTMLElement>('[data-instance-heading]');
    const fallback = legend ?? document.querySelector<HTMLElement>('[data-runtime-main]');
    fallback?.focus();
}

function onUpdate(next: AnswerValue): void {
    // A composite grid (G4b), a geo field (G5b2), and a media field (G6) are all banned from a repeatable
    // section by the publish gate, so an instance answer is always a scalar/list EngineValue — the cast narrows
    // the shared AnswerValue union (minus the grid objects, the geo envelope, and the media ref list) to what
    // setInstanceAnswer stores.
    runtime.setInstanceAnswer(
        props.sectionKey,
        props.index,
        props.field.key,
        next as Exclude<AnswerValue, Record<string, string> | Record<string, Record<string, string>> | GeoEnvelope | MediaAttachmentRef[]>,
    );
}

function onFocusout(): void {
    runtime.markInstanceTouched(props.sectionKey, props.index, props.field.key);
}
</script>

<template>
    <Transition name="field-collapse">
        <div v-if="relevant" :id="anchorId" ref="rootEl" class="instance-field" @focusout="onFocusout">
            <FieldInput
                :field="encodeField"
                :model-value="value"
                :required-marker="marker"
                :error="error"
                @update:model-value="onUpdate"
            />
        </div>
    </Transition>
</template>

<style scoped>
.instance-field {
    display: block;
}

/* Match FieldRow's expand/collapse so a relevance change inside an instance doesn't visibly jump (UX §4.1). */
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
