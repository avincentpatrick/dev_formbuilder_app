<script setup lang="ts">
/**
 * The post-submit confirmation (UX §9): a full-screen thank-you with the derived human reference number and an
 * option to submit another response. Focus moves to the heading on arrival so a screen reader is told the flow
 * has concluded (§10.2).
 */
import { onMounted, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';

defineProps<{ reference: string; message: string }>();
defineEmits<{ restart: [] }>();

const heading = ref<HTMLElement | null>(null);
onMounted(() => heading.value?.focus());
</script>

<template>
    <div class="confirmation">
        <div class="confirmation__card">
            <svg class="confirmation__icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2" />
                <path
                    d="M15 24l6 6 12-12"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <h1 ref="heading" tabindex="-1" class="confirmation__title">{{ message }}</h1>
            <p class="confirmation__ref">Reference: <strong>{{ reference }}</strong></p>
            <MdsButton variant="secondary" @click="$emit('restart')">Submit another response</MdsButton>
        </div>
    </div>
</template>

<style scoped>
.confirmation {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.confirmation__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-4);
    max-width: 32rem;
    padding: var(--mds-space-8);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg, var(--mds-radius-md));
    box-shadow: var(--mds-shadow-1);
}

.confirmation__icon {
    width: 56px;
    height: 56px;
    color: var(--mds-color-action-primary-fg);
}

.confirmation__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-type-heading-2-font-weight);
    color: var(--mds-color-text-heading);
}

.confirmation__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.confirmation__ref {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation__ref strong {
    font-family: var(--mds-font-family-mono, monospace);
    color: var(--mds-color-text-body);
}
</style>
