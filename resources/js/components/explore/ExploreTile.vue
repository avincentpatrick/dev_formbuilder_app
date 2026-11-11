<script setup lang="ts">
// One saved chart on the board (M235). It fetches its own result when it mounts, so the board paints at
// once and one refused or slow chart cannot hold up the others.
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MdsBadge, MdsCard } from '@meridian/design-system';
import ExploreResult from './ExploreResult.vue';
import { fetchChartResult } from './exploreClient';
import type { Refusal, Result, SavedChart } from './types';

const props = defineProps<{ chart: SavedChart }>();

const result = ref<Result | null>(null);
const refusal = ref<Refusal | null>(null);
const loading = ref(true);

onMounted(async () => {
    const payload = await fetchChartResult(props.chart.id);

    result.value = payload.result;
    refusal.value = payload.refusal;
    loading.value = false;
});
</script>

<template>
    <MdsCard class="explore-tile">
        <template #header>
            <div class="explore-tile__header">
                <h2 class="explore-tile__title">
                    <Link :href="`/explore/charts/${chart.id}`" class="explore-tile__link">{{ chart.name }}</Link>
                </h2>
                <div class="explore-tile__meta">
                    <span v-if="chart.source_label !== null" class="explore-tile__source">{{ chart.source_label }}</span>
                    <MdsBadge v-if="chart.is_shared && chart.is_mine" variant="info" label="Shared" />
                    <MdsBadge v-else-if="chart.owner !== null" variant="neutral" :label="`By ${chart.owner}`" />
                </div>
            </div>
        </template>

        <ExploreResult :result="result" :refusal="refusal" :loading="loading" :title="chart.name" compact />
    </MdsCard>
</template>

<style scoped>
.explore-tile {
    min-width: 0;
}

.explore-tile__header {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--mds-space-2) var(--mds-space-3);
}

.explore-tile__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    overflow-wrap: anywhere;
}

.explore-tile__link {
    color: var(--mds-color-text-heading);
    text-decoration: none;
}

.explore-tile__link:hover {
    text-decoration: underline;
}

.explore-tile__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.explore-tile__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.explore-tile__source {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
