<script setup lang="ts">
/**
 * Pagination control (Increment F7) — prev/next navigation plus a "Page X of Y · N results" summary, for
 * server-paginated lists like the submissions inbox. Kept minimal and reusable (the one design-system rule):
 * it owns no data, it only reports the requested page via `update:page`; the page fetches and re-renders.
 *
 * The whole control is a labelled `<nav>`; the current page is announced via `aria-current`. When there is a
 * single page it collapses to just the result count (no dead prev/next buttons). Consumes semantic tokens only.
 */
import IconButton from '../IconButton/IconButton.vue';

const props = withDefaults(
    defineProps<{
        currentPage: number;
        lastPage: number;
        total: number;
        perPage?: number;
    }>(),
    { perPage: 25 },
);

const emit = defineEmits<{ 'update:page': [page: number] }>();

function go(page: number): void {
    if (page >= 1 && page <= props.lastPage && page !== props.currentPage) {
        emit('update:page', page);
    }
}
</script>

<template>
    <nav v-if="total > 0" class="mds-pagination" aria-label="Pagination">
        <p class="mds-pagination__summary">
            <template v-if="lastPage > 1">
                Page <span aria-current="page">{{ currentPage }}</span> of {{ lastPage }}
                <span class="mds-pagination__dot" aria-hidden="true">·</span>
            </template>
            {{ total }} {{ total === 1 ? 'result' : 'results' }}
        </p>
        <div v-if="lastPage > 1" class="mds-pagination__controls">
            <IconButton
                icon="chevron-left"
                label="Previous page"
                size="sm"
                :disabled="currentPage <= 1"
                @click="go(currentPage - 1)"
            />
            <IconButton
                icon="chevron-right"
                label="Next page"
                size="sm"
                :disabled="currentPage >= lastPage"
                @click="go(currentPage + 1)"
            />
        </div>
    </nav>
</template>

<style scoped>
.mds-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding-top: var(--mds-space-4);
}

.mds-pagination__summary {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-pagination__dot {
    margin: 0 var(--mds-space-1);
}

.mds-pagination__controls {
    display: inline-flex;
    gap: var(--mds-space-1);
}
</style>
