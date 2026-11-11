<script setup lang="ts">
// The outcome of running a chart, in its three states (M235): still loading, refused with a sentence, or
// drawn — with the range it covers above it and what it left out beneath it.
//
// Used by a board tile and by the builder's preview, so the two cannot come to describe the same chart
// differently.
import { computed } from 'vue';
import { MdsEmptyState, MdsSkeleton } from '@meridian/design-system';
import ExploreChart from './ExploreChart.vue';
import { disclosures, rangeSentence } from './result-view';
import type { Refusal, Result } from './types';

const props = withDefaults(
    defineProps<{
        result: Result | null;
        refusal: Refusal | null;
        loading: boolean;
        title: string;
        showTable?: boolean;
        /** A tile keeps its notes short: the builder is where a reader goes for the detail. */
        compact?: boolean;
    }>(),
    { showTable: false, compact: false },
);

const notes = computed(() => (props.result === null ? [] : disclosures(props.result)));
const range = computed(() => (props.result === null ? '' : rangeSentence(props.result)));
</script>

<template>
    <div class="explore-result" :aria-busy="loading">
        <div v-if="loading && result === null" class="explore-result__loading">
            <MdsSkeleton variant="block" height="220px" />
            <span class="explore-result__sr">Loading the chart</span>
        </div>

        <MdsEmptyState
            v-else-if="refusal !== null"
            illustration="lock"
            headline="This chart cannot be drawn"
            :description="refusal.message"
        />

        <template v-else-if="result !== null">
            <p class="explore-result__range">{{ range }}</p>

            <ExploreChart :result="result" :title="title" :show-table="showTable" />

            <ul v-if="notes.length > 0 && !compact" class="explore-result__notes">
                <li v-for="note in notes" :key="note">{{ note }}</li>
            </ul>
        </template>
    </div>
</template>

<style scoped>
/* `position: relative` so the clipped loading label below resolves its containing block here and cannot
   extend the document's scrollable width — the hazard the chart primitives record at length. */
.explore-result {
    position: relative;
    min-width: 0;
}

.explore-result__range {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.explore-result__notes {
    margin: var(--mds-space-4) 0 0;
    padding-inline-start: var(--mds-space-5);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.explore-result__notes li + li {
    margin-top: var(--mds-space-1);
}

.explore-result__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
