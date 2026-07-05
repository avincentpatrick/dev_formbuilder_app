<script setup lang="ts">
/**
 * Surface container (DSR §3.5). Static by default; `interactive` renders a real <button>/<a>
 * root (never a div with a click handler) with hover-raise + focus-visible ring. Border and
 * shadow are used together so cards stay legible where shadows render faintly.
 */
withDefaults(
    defineProps<{
        interactive?: boolean;
        as?: 'div' | 'button' | 'a';
        href?: string;
        type?: 'button' | 'submit';
    }>(),
    { interactive: false, as: 'div', type: 'button' },
);
</script>

<template>
    <component
        :is="as"
        class="mds-card"
        :class="{ 'mds-card--interactive': interactive }"
        :href="as === 'a' ? href : undefined"
        :type="as === 'button' ? type : undefined"
    >
        <div v-if="$slots.header" class="mds-card__header"><slot name="header" /></div>
        <div class="mds-card__body"><slot /></div>
        <div v-if="$slots.footer" class="mds-card__footer"><slot name="footer" /></div>
    </component>
</template>

<style scoped>
.mds-card {
    display: block;
    padding: var(--mds-space-5);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-1);
    color: var(--mds-color-text-body);
}

/* Interactive cards reset native button/link chrome and become raise-on-hover targets. */
.mds-card--interactive {
    width: 100%;
    text-align: left;
    font: inherit;
    cursor: pointer;
    text-decoration: none;
    transition:
        box-shadow var(--mds-duration-base) var(--mds-ease-standard),
        border-color var(--mds-duration-base) var(--mds-ease-standard);
}

.mds-card--interactive:hover {
    box-shadow: var(--mds-shadow-2);
    border-color: var(--mds-color-border-strong);
}

.mds-card--interactive:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-card__header {
    margin-bottom: var(--mds-space-3);
}

.mds-card__footer {
    margin-top: var(--mds-space-4);
}
</style>
