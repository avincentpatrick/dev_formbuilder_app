<script setup lang="ts">
/**
 * The mid-fill language switcher (UX §6). Lists only the FORM's own `supported_locales`. Switching locale
 * re-renders every label/hint/option/error from that locale's translations WITHOUT touching answers, the
 * current step, or already-surfaced validation state — the engine is simply re-run with the new `locale`
 * (only error-message resolution depends on it). Hidden when the form has a single locale.
 */
import { computed } from 'vue';
import { MdsSelect } from '@meridian/design-system';
import { useRuntime } from '../composables/context';

const runtime = useRuntime();

function displayName(code: string): string {
    try {
        const names = new Intl.DisplayNames([code], { type: 'language' });
        return names.of(code) ?? code;
    } catch {
        return code;
    }
}

const options = computed(() =>
    runtime.renderModel.form.supported_locales.map((code) => ({ value: code, label: displayName(code) })),
);

const visible = computed(() => options.value.length > 1);

function onChange(code: string): void {
    runtime.locale.value = code;
}
</script>

<template>
    <label v-if="visible" class="language-switcher">
        <span class="language-switcher__label">Language</span>
        <MdsSelect :model-value="runtime.locale.value" :options="options" @update:model-value="onChange" />
    </label>
</template>

<style scoped>
.language-switcher {
    display: inline-flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 8rem;
}

.language-switcher__label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}
</style>
