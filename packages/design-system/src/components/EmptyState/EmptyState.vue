<script setup lang="ts">
/**
 * Empty state (DSR §3.10): line-art illustration, one headline, one line of secondary copy, and
 * AT MOST ONE primary CTA (the `action` slot). Use the copy itself to distinguish a first-run
 * empty ("Create your first form") from a permission-restricted or filtered-to-zero empty.
 */
withDefaults(
    defineProps<{
        headline: string;
        description?: string;
        illustration?: 'default' | 'search' | 'lock';
    }>(),
    { illustration: 'default' },
);
</script>

<template>
    <div class="mds-empty">
        <svg
            class="mds-empty__art"
            viewBox="0 0 64 64"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            <template v-if="illustration === 'search'">
                <circle cx="28" cy="28" r="14" />
                <path d="M38 38l10 10" />
            </template>
            <template v-else-if="illustration === 'lock'">
                <rect x="18" y="28" width="28" height="22" rx="2" />
                <path d="M24 28v-6a8 8 0 0 1 16 0v6" />
            </template>
            <template v-else>
                <rect x="16" y="10" width="28" height="44" rx="2" />
                <path d="M24 24h16 M24 32h16 M24 40h10" />
            </template>
        </svg>

        <h3 class="mds-empty__headline">{{ headline }}</h3>
        <p v-if="description" class="mds-empty__desc">{{ description }}</p>
        <div v-if="$slots.action" class="mds-empty__action"><slot name="action" /></div>
    </div>
</template>

<style scoped>
.mds-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-10) var(--mds-space-6);
}

.mds-empty__art {
    width: 64px;
    height: 64px;
    color: var(--mds-color-border-strong);
    margin-bottom: var(--mds-space-1);
}

.mds-empty__headline {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-empty__desc {
    max-width: 42ch;
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-empty__action {
    margin-top: var(--mds-space-2);
}
</style>
