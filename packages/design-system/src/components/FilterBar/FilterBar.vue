<script setup lang="ts">
/**
 * The shared filter surface for a row-list page (Increment J1e, DSR §3.2).
 *
 * A labelled `.mds-filterbar` section element, an uppercase level-2 heading, and a responsive grid the
 * caller fills with `MdsFormField`-wrapped controls. Six pages use it: forms, submissions, members,
 * webhooks, feedback and the audit log.
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT, AND THAT IS DELIBERATE. J1a lost
 * a Storybook build to a bare `header` tag literal inside a docblock: the vue3-vite preset preserves
 * comments for docgen, so the SFC parser tokenises comment bodies that Vitest, vue-tsc and the app's own
 * Vite build all skip — and the error names a line past the end of the file. Only the merge-blocking job
 * sees it, and that job has never been runnable in these containers. Name the element or the class instead.
 *
 * ── ⚠️ THE LEVEL-2 HEADING IS LOAD-BEARING AND MUST NEVER BECOME CONDITIONAL ───────────────────────────
 * `PageHeader` renders the page's level-1 heading and `MdsEmptyState` renders a level-3 one. Without a
 * heading between them, axe's `heading-order` rule fails — but ONLY while the list is empty, because a
 * populated `MdsDataTable` renders no heading at all. That is the state a keyword filter creates more often
 * than any other, and it is the state a seeded e2e database almost never lands in. Two pages had already
 * worked this out independently and written the same comment; putting the heading in the component is what
 * stops the seventh page from rediscovering it the hard way.
 *
 * It is deliberately NOT a `headingLevel` prop. Every list page in this app puts its filters directly under
 * a `PageHeader`, so the correct level is always 2; a prop would offer a choice whose wrong answers all fail
 * the same silent way.
 *
 * ── The heading is visually quiet, not visually hidden ─────────────────────────────────────────────────
 * Small, uppercase and secondary-coloured rather than `sr-only`: a filter bar with no visible caption reads
 * as a stray row of controls, and the word "Filters" is what tells a returning user why their list looks
 * short. §4.2's "a visible label beats an invisible one wherever there is room" applies.
 */
import { useId } from 'vue';

const headingId = useId();
</script>

<template>
    <section class="mds-filterbar" :aria-labelledby="headingId">
        <h2 :id="headingId" class="mds-filterbar__heading">Filters</h2>

        <div class="mds-filterbar__grid">
            <slot />
        </div>
    </section>
</template>

<style scoped>
.mds-filterbar {
    margin-bottom: var(--mds-space-5);
}

.mds-filterbar__heading {
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-transform: uppercase;
    /* JR2: was a literal `0.04em` — the one hard-coded typographic value in the package, and exactly
       the orphan `tracking.wide` was added for. Now the same letting as the stat-tile label and the
       table header, so the three uppercased micro-labels in the product finally agree. */
    letter-spacing: var(--mds-tracking-wide);
    color: var(--mds-color-text-secondary);
}

/*
 * `auto-fit` + a 12rem floor rather than a breakpoint: the six pages carry between one and five controls,
 * and a media query would have to know how many. This collapses to one column on a narrow viewport with no
 * invented breakpoint, which is what §6's tokenized-band rule asks for.
 */
.mds-filterbar__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--mds-space-3);
}
</style>
