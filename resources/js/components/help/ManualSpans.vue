<script setup lang="ts">
/**
 * One run of the user manual's text (M162) — plain text, an on-screen label in bold, or a link.
 *
 * ⛔ TEXT NODES ONLY. Every leaf is Vue interpolation; there is no markup sink here, which is what keeps
 * `raw-html-sink-census.test.ts` at its one allowed sink. A `#id` link jumps within the manual and stays a plain
 * anchor; a `/path` link goes through Inertia's `Link`, so opening a page of the app keeps the shell.
 */
import { Link } from '@inertiajs/vue3';
import type { ManualSpan } from './manual';

defineProps<{ spans: ManualSpan[] }>();
</script>

<template>
    <template v-for="(span, i) in spans" :key="i">
        <template v-if="span.kind === 'text'">{{ span.text }}</template>
        <strong v-else-if="span.kind === 'ui'" class="manual-ui">{{ span.label }}</strong>
        <a v-else-if="span.href.startsWith('#')" class="manual-link" :href="span.href">{{ span.text }}</a>
        <Link v-else class="manual-link" :href="span.href">{{ span.text }}</Link>
    </template>
</template>

<style scoped>
.manual-ui {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

/* Underlined, not colour alone: a link inside running text must be told apart without colour (WCAG 1.4.1).
   `-fg`, never `-bg` — the darker text role is the one that holds 4.5:1 on every brand ramp (DSR §2). */
.manual-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
}
</style>
