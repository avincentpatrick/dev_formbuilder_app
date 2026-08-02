<script setup lang="ts">
/**
 * Renders one step's fields (a section, or the leading section-less block). The heading is programmatically
 * focusable (tabindex="-1") so step navigation and focus-rescue can land on it (UX §10.2). Individual field
 * visibility is handled per-row by relevance; a section that is entirely irrelevant is dropped upstream by
 * the store's `visibleSteps`.
 */
import { computed } from 'vue';
import FieldRow from './FieldRow.vue';
import RepeatGroup from './RepeatGroup.vue';
import { useRuntime } from '../composables/context';
import type { RuntimeStep } from '../composables/useFormRuntime';

const props = defineProps<{ step: RuntimeStep }>();
const runtime = useRuntime();

const section = computed(() =>
    props.step.sectionKey === null
        ? null
        : (runtime.renderModel.sections.find((s) => s.key === props.step.sectionKey) ?? null),
);

// Increment H6b — locale-resolved then hole-filled through the store's seam. No repeat scope: a section
// sits BEFORE its own members positionally, so a hole in its heading naming one is a forward reference
// the publish gate refuses. Its holes can only ever name flat fields.
const title = computed(() => (section.value ? runtime.sectionTitleFor(section.value) : null));

const description = computed(() => (section.value ? runtime.sectionDescriptionFor(section.value) : null));

const fields = computed(() =>
    props.step.fieldKeys
        .map((key) => runtime.renderModel.fields.find((f) => f.key === key))
        .filter((f): f is NonNullable<typeof f> => f !== undefined),
);
</script>

<template>
    <!-- `data-section-key` (Increment H21b) lets single-page mode identify WHICH section held focus before a
         relevance change removed it — the rescue runs after the unmount, so it cannot ask the component. -->
    <section class="section" data-section :data-section-key="step.key">
        <header v-if="title" class="section__head">
            <h2 class="section__title" tabindex="-1" data-section-heading>{{ title }}</h2>
            <p v-if="description" class="section__desc">{{ description }}</p>
        </header>
        <RepeatGroup v-if="step.isRepeat && section" :section="section" />
        <div v-else class="section__fields">
            <FieldRow v-for="field in fields" :key="field.key" :field="field" />
        </div>
    </section>
</template>

<style scoped>
.section {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.section__head {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.section__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.section__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.section__desc {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.section__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}
</style>
