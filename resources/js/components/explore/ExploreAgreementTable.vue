<script setup lang="ts">
// An agreement list, drawn (M250, `agreement-report`, `D234`; ADR-0025 §D10): how many items are on the short list,
// to discuss and short of views, the rule they were counted by, and every item in the order a meeting reads them —
// the short list highest first, then the rest most-disagreed first. The server decided every number and status; this
// only lays them out, with the shared table, tiles and badges.
//
// On a board tile (`compact`) it shows the three counts and the first few short-listed items: the builder is where a
// reader goes for the whole list.
import { computed } from 'vue';
import { MdsBadge, MdsDataTable, MdsStatTile, type DataTableColumn } from '@meridian/design-system';
import { answersSentence, countedLabel, lineContext, ruleSentence, shareLabel, statusLabel, statusVariant } from './agreement-view';
import type { AgreementResult, AgreementStatus } from './types';

const props = withDefaults(
    defineProps<{
        list: AgreementResult;
        title: string;
        compact?: boolean;
    }>(),
    { compact: false },
);

const columns: DataTableColumn[] = [
    { key: 'item', header: 'Item' },
    { key: 'views', header: 'Gave a view', align: 'end' },
    { key: 'left_out', header: 'Left out', align: 'end' },
    { key: 'counted', header: 'Counted answer' },
    { key: 'share', header: 'Share agreeing', align: 'end' },
    { key: 'status', header: 'Status' },
];

const rows = computed(() =>
    props.list.items.map((line) => ({
        id: line.key,
        item: line.label,
        context: lineContext(line),
        answers: answersSentence(line),
        multiple: line.multiple,
        views: line.views.toLocaleString(),
        left_out: line.left_out.toLocaleString(),
        counted: countedLabel(line),
        share: shareLabel(line.share),
        status: line.status as AgreementStatus,
    })),
);

const rule = computed(() => ruleSentence(props.list));
const shortListed = computed(() => props.list.items.filter((line) => line.status === 'short_list').slice(0, 5));
</script>

<template>
    <div class="agreement">
        <div class="agreement__tiles" :class="{ 'agreement__tiles--compact': compact }">
            <MdsStatTile
                label="On the short list"
                icon="check"
                :value="list.summary.short_list"
                :caption="`of ${list.summary.items.toLocaleString()} ${list.summary.items === 1 ? 'item' : 'items'}`"
                value-size="compact"
            />
            <MdsStatTile label="To discuss" icon="feedback" :value="list.summary.discuss" value-size="compact" />
            <MdsStatTile label="Too few views" icon="info" :value="list.summary.too_few" value-size="compact" />
        </div>

        <template v-if="compact">
            <ul v-if="shortListed.length > 0" class="agreement__short" aria-label="First items on the short list">
                <li v-for="line in shortListed" :key="line.key">
                    {{ line.label }} <span class="agreement__muted">· {{ shareLabel(line.share) }}</span>
                </li>
            </ul>
        </template>

        <template v-else>
            <p class="agreement__rule">{{ rule }}</p>

            <MdsDataTable :columns="columns" :rows="rows" row-key="id" :caption="`${title}: how far respondents agree, item by item`">
                <template #cell-item="{ row }">
                    <span class="agreement__item">{{ row.item }}</span>
                    <span v-if="row.context !== ''" class="agreement__muted agreement__line">{{ row.context }}</span>
                    <span v-if="row.answers !== ''" class="agreement__muted agreement__line">
                        {{ row.answers }}<template v-if="row.multiple"> · several answers each</template>
                    </span>
                </template>
                <template #cell-status="{ row }">
                    <MdsBadge :variant="statusVariant(row.status)" :label="statusLabel(row.status)" />
                </template>
                <template #empty>
                    <p class="agreement__muted">No question on this list yet.</p>
                </template>
            </MdsDataTable>
        </template>
    </div>
</template>

<style scoped>
.agreement {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}

/* Three tiles that wrap to one column on a phone, never a sideways scroll. */
.agreement__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 10rem), 1fr));
    gap: var(--mds-space-3);
}

.agreement__tiles--compact {
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 7.5rem), 1fr));
}

.agreement__rule {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.agreement__item {
    display: block;
    font-weight: 600;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.agreement__line {
    display: block;
    margin-top: var(--mds-space-1);
    overflow-wrap: anywhere;
}

.agreement__muted {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.agreement__short {
    margin: 0;
    padding-inline-start: var(--mds-space-5);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.agreement__short li + li {
    margin-top: var(--mds-space-1);
}
</style>
