<script setup lang="ts">
// One question's answer-value aggregate (ADR-0011 §D3/§D4, Increment H24b2).
//
// Three renderings, and the boundaries between them are the decision, not the layout:
//   · REFUSED — §D4's "a mixed-type key is a visible refusal, never a silent average". The server's
//     message names the version at which the type changed; no number appears beside it, because the
//     number that could be computed would be the wrong one.
//   · NUMERIC — min/max/average/median, over the rows that actually projected.
//   · CATEGORICAL — a bar chart plus its full table.
//
// The COVERAGE DISCLOSURE renders in all three non-refused cases and is not optional. §D3(iii): the
// projector drops rows silently WITHIN a flagged field (a non-numeric answer under a `number` index, an
// unparseable date), so a count over the index under-counts against `submissions` per field. A chart with
// no coverage line is a chart claiming completeness it does not have.
import { computed } from 'vue';
import {
    MdsBarChart,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsSpinner,
    MdsStatTile,
    type BarDatum,
    type DataTableColumn,
} from '@meridian/design-system';
import type { QuestionAggregate } from './types';

const props = defineProps<{
    question: QuestionAggregate | null;
    /**
     * A question is selected but its aggregate has not arrived yet.
     *
     * This exists because of what the page looked like without it: the radio was checked and the card still
     * read "Pick a question" — two controls on one screen disagreeing about whether a question had been
     * picked. Locally the gap is seconds; on a fast host it is short but never zero, and "the empty state is
     * brief" is not the same as "the empty state is true".
     */
    loading?: boolean;
}>();

/**
 * `value_boolean::text` comes back as the literal strings 'true' / 'false'. Mapped here rather than in
 * SQL because the aggregator's shape is the byte-diffed API response, and "true" is the honest wire value.
 */
function categoryLabel(key: string | null): string {
    if (key === null) return 'No answer';
    if (props.question?.indexed_data_type !== 'boolean') return key;

    return key === 'true' ? 'Yes' : 'No';
}

const bars = computed<BarDatum[]>(() => {
    const rows = props.question?.rows ?? [];

    const list: BarDatum[] = rows.map((row, index) => ({
        key: row.key ?? `null-${index}`,
        label: categoryLabel(row.key),
        value: row.count,
    }));

    const other = props.question?.other ?? null;
    if (other !== null) {
        list.push({
            key: 'other',
            label: `Other (${other.categories} ${other.categories === 1 ? 'value' : 'values'})`,
            value: other.count,
            neutral: true,
        });
    }

    return list;
});

const tableRows = computed(() => bars.value.map((bar) => ({ key: bar.key, label: bar.label, count: bar.value })));

const columns: DataTableColumn[] = [
    { key: 'label', header: 'Answer' },
    { key: 'count', header: 'Responses', align: 'end' },
];

const coverage = computed(() => {
    const c = props.question?.coverage;
    if (c === undefined) return '';

    return `${c.indexed.toLocaleString()} of ${c.countable.toLocaleString()} responses in this period carry a value for this question.`;
});

const summaryTiles = computed(() => {
    const s = props.question?.summary;
    if (!s) return [];

    return [
        { label: 'Answers', value: s.count.toLocaleString() },
        { label: 'Lowest', value: s.min === null ? null : s.min.toLocaleString() },
        { label: 'Highest', value: s.max === null ? null : s.max.toLocaleString() },
        { label: 'Average', value: s.average === null ? null : s.average.toLocaleString() },
        { label: 'Median', value: s.median === null ? null : s.median.toLocaleString() },
    ];
});
</script>

<template>
    <MdsCard v-if="loading">
        <div class="analytics__question-loading" role="status">
            <MdsSpinner />
            <p class="analytics__coverage">Summarising this question…</p>
        </div>
    </MdsCard>

    <MdsCard v-else-if="question !== null">
        <template #header>
            <h3 class="analytics__card-title">{{ question.label }}</h3>
        </template>

        <MdsEmptyState
            v-if="question.refused"
            illustration="lock"
            headline="This question cannot be summarised"
            :description="question.message ?? undefined"
        />

        <template v-else>
            <p class="analytics__coverage">{{ coverage }}</p>

            <p v-if="question.type_changed_at_version !== null" class="analytics__coverage">
                This question changed type in version {{ question.type_changed_at_version }}; only values
                recorded under its current type are counted.
            </p>

            <p v-if="!question.indexed_column" class="analytics__coverage">
                Yes/no answers are not separately indexed, so this summary is slower to produce than the others.
            </p>

            <div v-if="question.summary !== null" class="analytics__question-tiles">
                <MdsStatTile
                    v-for="tile in summaryTiles"
                    :key="tile.label"
                    :label="tile.label"
                    icon="hash"
                    :value="tile.value"
                    :unavailable="tile.value === null"
                    :unavailable-note="tile.value === null ? 'No numeric answers were recorded.' : undefined"
                />
            </div>

            <template v-else-if="bars.length > 0">
                <MdsBarChart
                    :data="bars"
                    :title="question.label"
                    category-label="Answer"
                    value-label="Responses"
                />
                <div class="analytics__question-table">
                    <MdsDataTable
                        :columns="columns"
                        :rows="tableRows"
                        :caption="`Every recorded answer to ${question.label}, including any folded into Other`"
                        row-key="key"
                    />
                </div>
            </template>

            <MdsEmptyState
                v-else
                headline="No answers recorded yet"
                description="This question is set up for reporting, but no response in this period has an answer for it."
            />
        </template>
    </MdsCard>
</template>

<style scoped>
.analytics__card-title {
    margin: 0;
    font-size: var(--mds-type-body-lg-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__coverage {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.analytics__question-loading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-6) 0;
    justify-content: center;
}

.analytics__question-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 140px), 1fr));
    gap: var(--mds-space-3);
}

.analytics__question-table {
    margin-top: var(--mds-space-4);
}
</style>
