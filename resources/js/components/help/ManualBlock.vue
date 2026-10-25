<script setup lang="ts">
/**
 * One block of the user manual (M162) — a paragraph, numbered steps, a bulleted list, a tip, or a picture (M163).
 *
 * A tip is `MdsCallout` — a static note with `role="note"`, never a live region: it is part of the page, read in
 * order, exactly the posture a form's content callouts take (`NoteContent.vue`).
 *
 * A picture is a plain `<img>` from `public/images/help/`, at its CSS size and never wider than the card, with its
 * description as `alt` and a visible caption. It is light-themed in both themes — a screenshot of a light screen —
 * so it carries its own border rather than relying on the card's background to frame it.
 *
 * A table (M187, `R-3e6358ed` — what each question type can do) is the shared `MdsDataTable`, so it scrolls inside its
 * own frame with keyboard access when it is wider than the page, and turns into one card per row on a phone; the page
 * itself never scrolls sideways. Each row's head is drawn by `ManualSpans`, so its labels are bold like every quote.
 */
import { computed } from 'vue';
import { MdsCallout, MdsDataTable, type DataTableColumn } from '@meridian/design-system';
import ManualSpans from './ManualSpans.vue';
import type { ManualBlock, ManualSpan } from './manual';

const props = defineProps<{ block: ManualBlock }>();

type TableRow = Record<string, unknown> & { key: string; head: ManualSpan[] };

const tableColumns = computed<DataTableColumn[]>(() =>
    props.block.kind === 'table' ? props.block.columns.map((col) => ({ key: col.key, header: col.heading })) : [],
);
const tableRows = computed<TableRow[]>(() =>
    props.block.kind === 'table' ? props.block.rows.map((row) => ({ ...row.cells, key: row.key, head: row.head })) : [],
);
</script>

<template>
    <p v-if="block.kind === 'paragraph'" class="manual-block__text"><ManualSpans :spans="block.spans" /></p>
    <ol v-else-if="block.kind === 'steps'" class="manual-block__list manual-block__list--steps">
        <li v-for="(item, i) in block.items" :key="i"><ManualSpans :spans="item" /></li>
    </ol>
    <ul v-else-if="block.kind === 'list'" class="manual-block__list">
        <li v-for="(item, i) in block.items" :key="i"><ManualSpans :spans="item" /></li>
    </ul>
    <figure v-else-if="block.kind === 'figure'" class="manual-block__figure">
        <img
            class="manual-block__image"
            :src="block.src"
            :alt="block.alt"
            :width="block.width"
            :height="block.height"
            loading="lazy"
            decoding="async"
        />
        <figcaption class="manual-block__caption">{{ block.caption }}</figcaption>
    </figure>
    <div v-else-if="block.kind === 'table'" class="manual-block__table">
        <MdsDataTable :columns="tableColumns" :rows="tableRows" row-key="key" :caption="block.caption">
            <template #cell-type="{ row }"><ManualSpans :spans="row.head" /></template>
        </MdsDataTable>
    </div>
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

.manual-block__figure {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    align-items: flex-start;
    margin: 0;
}

.manual-block__image {
    display: block;
    max-width: 100%;
    height: auto;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

/* Never wider than the card: the table scrolls (or stacks into cards) inside it. */
.manual-block__table {
    min-width: 0;
    max-width: 100%;
}

.manual-block__caption {
    max-width: 72ch;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
