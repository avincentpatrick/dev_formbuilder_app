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
    /* JR2: the page-level card tier (DSR §2.6). `xl` shipped unconsumed in JR1 precisely so this
       wiring would be one component edit rather than a redefinition of `md`, which every control in
       the app also reads. Padding deliberately stays at `space-5`: the two approved mockups disagree
       (24px on a panel, 18px on a grid card), 20px sits between them, and padding is the one value
       here with 52 call sites and no visual-regression net behind it. */
    border-radius: var(--mds-radius-xl);
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

/* JR2: hover picks up the ACCENT edge from the approved direction, not a darker grey. `-fg`, never
   `-bg` — a border is a coloured edge and `-bg` guarantees contrast only against text printed ON it
   (the J2a WCAG 1.4.11 finding). Measured on BOTH grounds, because a card edge has two — the card's
   own fill inside it and the page behind it: `#1156B2` on the card's `#FFFFFF` is 7.01:1 and on the
   canvas `#F5F7FC` is 6.54:1; dark `#8FBCFF` on `#1a2130` is 8.29:1 and on the dark canvas `#0f131c`
   is 9.56:1. All four clear the 3:1 a non-text indicator needs. (A draft of this comment quoted the
   first number while calling `#FFFFFF` "the surface behind it" — that is the card's own fill, not
   what is behind it.) No transform is added — the mockup shows none, and a hover translate would raise a
   reduced-motion question that a shadow and a border colour do not. */
.mds-card--interactive:hover {
    box-shadow: var(--mds-shadow-2);
    border-color: var(--mds-color-action-primary-fg);
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
