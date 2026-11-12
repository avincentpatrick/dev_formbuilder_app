<script setup lang="ts">
// An explorer result as a plain table (M236) — the Table chart type, and what any chart reduces to.
// A real `<table>` with a caption and scoped headers, scrolled inside its own wrapper so a wide result
// never widens the page.
import { computed } from 'vue';
import { toTable } from './result-view';
import type { Result } from './types';

const props = defineProps<{ result: Result; title: string }>();

const table = computed(() => toTable(props.result));
</script>

<template>
    <div class="explore-table">
        <table class="explore-table__table">
            <caption class="explore-table__caption">{{ title }}</caption>
            <thead>
                <tr>
                    <th v-for="(header, index) in table.headers" :key="index" scope="col">{{ header }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in table.rows" :key="row.key">
                    <th scope="row">{{ row.label }}</th>
                    <td v-for="(cell, index) in row.cells" :key="index">{{ cell }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.explore-table {
    overflow-x: auto;
    min-width: 0;
}

.explore-table__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.explore-table__caption {
    text-align: start;
    padding-bottom: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.explore-table__table th,
.explore-table__table td {
    padding: var(--mds-space-2) var(--mds-space-3);
    text-align: start;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.explore-table__table thead th {
    color: var(--mds-color-text-secondary);
    font-weight: 600;
    white-space: nowrap;
}

.explore-table__table td {
    font-variant-numeric: tabular-nums;
}
</style>
