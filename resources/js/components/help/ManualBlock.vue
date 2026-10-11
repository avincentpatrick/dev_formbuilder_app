<script setup lang="ts">
/**
 * One block of the user manual (M162) — a paragraph, numbered steps, a bulleted list, or a tip.
 *
 * A tip is `MdsCallout` — a static note with `role="note"`, never a live region: it is part of the page, read in
 * order, exactly the posture a form's content callouts take (`NoteContent.vue`).
 */
import { MdsCallout } from '@meridian/design-system';
import ManualSpans from './ManualSpans.vue';
import type { ManualBlock } from './manual';

defineProps<{ block: ManualBlock }>();
</script>

<template>
    <p v-if="block.kind === 'paragraph'" class="manual-block__text"><ManualSpans :spans="block.spans" /></p>
    <ol v-else-if="block.kind === 'steps'" class="manual-block__list manual-block__list--steps">
        <li v-for="(item, i) in block.items" :key="i"><ManualSpans :spans="item" /></li>
    </ol>
    <ul v-else-if="block.kind === 'list'" class="manual-block__list">
        <li v-for="(item, i) in block.items" :key="i"><ManualSpans :spans="item" /></li>
    </ul>
    <MdsCallout v-else :tone="block.tone"><ManualSpans :spans="block.spans" /></MdsCallout>
</template>

<style scoped>
.manual-block__text {
    max-width: 72ch;
    margin: 0;
    color: var(--mds-color-text-body);
}

.manual-block__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    max-width: 72ch;
    margin: 0;
    padding-inline-start: var(--mds-space-6);
    color: var(--mds-color-text-body);
}

.manual-block__list--steps li::marker {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}
</style>
