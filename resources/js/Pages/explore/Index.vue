<script setup lang="ts">
// The data explorer's board (M236, ADR-0025): every chart this member kept, and the ones colleagues shared
// with the workspace, each drawn live. Rendered inside the persistent AppLayout (assigned in app.ts).
//
// The page's props hold the charts' DECLARATIONS and no data. Each tile runs its own chart, as the member
// looking at it — see ExploreTile — so a chart shared over data its viewer may not read shows that viewer
// a sentence, and the rest of the board is unaffected.
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ExploreTile from '@/components/explore/ExploreTile.vue';
import type { SavedChart } from '@/components/explore/types';

const props = defineProps<{
    charts: SavedChart[];
    can: { create: boolean };
    limits: { max_charts: number };
}>();

const mine = computed(() => props.charts.filter((chart) => chart.is_mine));
const shared = computed(() => props.charts.filter((chart) => !chart.is_mine));
</script>

<template>
    <div class="explore">
        <Head title="Explore" />

        <PageHeader title="Explore" icon="chart-pie">
            <template #actions>
                <!-- `<Link>` wrapping a button, the forms list's pattern: `MdsButton as="a"` is a full browser
                     reload, which remounts the app shell. -->
                <Link v-if="can.create" href="/explore/new">
                    <MdsButton variant="primary" icon-left="plus">New chart</MdsButton>
                </Link>
            </template>
        </PageHeader>

        <p class="explore__intro">
            Build your own charts from the data you can already open here: responses, the answers to a form,
            forms, members and more. A chart is drawn fresh each time it is opened.
        </p>

        <MdsCard v-if="charts.length === 0" class="explore__empty">
            <MdsEmptyState
                headline="No charts yet"
                description="Pick a data source, choose what to group and count, and save the chart to keep it on this page."
            >
                <template v-if="can.create" #action>
                    <Link href="/explore/new">
                        <MdsButton variant="primary" icon-left="plus">Build a chart</MdsButton>
                    </Link>
                </template>
            </MdsEmptyState>
        </MdsCard>

        <section v-if="mine.length > 0" aria-labelledby="explore-mine-heading" class="explore__section">
            <h2 id="explore-mine-heading" class="explore__section-title">Your charts</h2>
            <div class="explore__grid">
                <ExploreTile v-for="chart in mine" :key="chart.id" :chart="chart" />
            </div>
        </section>

        <section v-if="shared.length > 0" aria-labelledby="explore-shared-heading" class="explore__section">
            <h2 id="explore-shared-heading" class="explore__section-title">Shared with the workspace</h2>
            <div class="explore__grid">
                <ExploreTile v-for="chart in shared" :key="chart.id" :chart="chart" />
            </div>
        </section>
    </div>
</template>

<style scoped>
.explore__intro {
    margin: 0 0 var(--mds-space-5);
    max-width: 44rem;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.explore__empty {
    padding: 0;
}

.explore__section + .explore__section {
    margin-top: var(--mds-space-6);
}

.explore__section-title {
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.explore__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr));
    gap: var(--mds-space-4);
    align-items: start;
}
</style>
