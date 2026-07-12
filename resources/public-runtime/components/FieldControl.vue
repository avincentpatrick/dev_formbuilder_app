<script setup lang="ts">
/**
 * Adapts a public-runtime `RenderField` + the store's live value onto the SHARED F4b `FieldInput.vue` — the
 * exact same control set the authenticated manual-encode channel uses (UX §1 parity). Translation resolution
 * (label/hint/option text) and the engine-derived error message + required marker are computed here so
 * `FieldInput` receives already-resolved, presentation-ready props.
 */
import { computed } from 'vue';
import FieldInput, { type AnswerValue, type EncodeField } from '@/components/submissions/FieldInput.vue';
import { resolveCascade, resolveMatrix, resolveOptional, resolveText } from '../lib/schema-mapping';
import { useRuntime } from '../composables/context';
import type { RenderField } from '../lib/types';

const props = defineProps<{ field: RenderField }>();
const runtime = useRuntime();

const marker = computed(() => runtime.requiredMarkerFor(props.field));

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
    matrix: resolveMatrix(props.field.matrix, runtime.locale.value),
    // Geo capture config (Increment G5b2) is labels-free, so it passes straight through with no resolution.
    geo: props.field.geo,
    supported: props.field.supported,
}));

const value = computed<AnswerValue>(() => {
    const current = runtime.answers[props.field.key];
    if (current === undefined || current === null) {
        return null;
    }
    if (Array.isArray(current)) {
        return current.map(String);
    }
    // A composite grid (Increment G4b) or a geo envelope (Increment G5b2) is an object; pass it through
    // untouched (grid cells are already strings; a geo envelope is the frozen GeoJSON shape).
    if (typeof current === 'object') {
        return current as AnswerValue;
    }
    return current;
});

function onUpdate(next: AnswerValue): void {
    runtime.setAnswer(props.field.key, next);
}
</script>

<template>
    <FieldInput
        :field="encodeField"
        :model-value="value"
        :required-marker="marker"
        :error="runtime.errorFor(field.key)"
        @update:model-value="onUpdate"
    />
</template>
