<script setup lang="ts">
/**
 * M246 (`grid-question-table`) — one grid row's own settings, under the row in the Grid tab: when the row is shown, which
 * of its cells are "Not needed" (a Grid's only), and the details drawn under its label. Folded behind a "Row settings"
 * button, so a long table stays a list of rows; the button says what is set while it is closed.
 *
 * The row's show-when reads other questions and other grids' always-asked cells (`D230`) — never this grid's own.
 */
import { MdsButton, MdsCheckbox } from '@meridian/design-system';
import { computed, ref, useId } from 'vue';

import ConditionEditor from './ConditionEditor.vue';
import ContentBlocksEditor from './ContentBlocksEditor.vue';
import type { ContentBlock } from './content-blocks';
import type { ReferenceCandidate } from './reference-candidates';
import type { ConditionCatalogue } from './types';

type Row = { value: string; label: string; relevant?: string | null; not_needed?: string[]; details?: ContentBlock[] | null };

const props = defineProps<{
    row: Row;
    index: number;
    /** A Grid's columns, for its "Not needed" ticks; empty for a Likert grid, whose rows have none. */
    columns: { value: string; label: string }[];
    catalogue: ConditionCatalogue;
    references?: ReferenceCandidate[];
    formId: string;
    /** What names this row elsewhere — renaming or removing it would leave them pointing at nothing. */
    namedBy?: string[];
}>();
const emit = defineEmits<{ 'update:row': [row: Row] }>();

const open = ref(false);
const panelId = useId();

const notNeeded = computed<string[]>(() => (Array.isArray(props.row.not_needed) ? props.row.not_needed : []));
const details = computed<ContentBlock[]>(() => (Array.isArray(props.row.details) ? props.row.details : []));

const summary = computed<string>(() => {
    const parts = [
        typeof props.row.relevant === 'string' && props.row.relevant.trim() !== '' ? 'shown only sometimes' : null,
        notNeeded.value.length > 0 ? `${notNeeded.value.length} not needed` : null,
        details.value.length > 0 ? 'has details' : null,
    ].filter((part): part is string => part !== null);

    return parts.length > 0 ? parts.join(' · ') : 'none set';
});

/** The row with one setting changed; an emptied setting is taken out, so a row with none looks as it always did. */
function withSetting(key: 'relevant' | 'not_needed' | 'details', value: unknown): Row {
    const next: Row = { ...props.row };
    if (value === null || (Array.isArray(value) && value.length === 0) || (typeof value === 'string' && value.trim() === '')) {
        delete next[key];
    } else {
        (next as Record<string, unknown>)[key] = value;
    }

    return next;
}

function toggleNotNeeded(column: string, checked: boolean): void {
    const next = checked ? [...notNeeded.value.filter((c) => c !== column), column] : notNeeded.value.filter((c) => c !== column);
    emit('update:row', withSetting('not_needed', props.columns.map((c) => c.value).filter((c) => next.includes(c))));
}
</script>

<template>
    <div class="grid-row-rules" :data-grid-row-rules="index">
        <p v-if="namedBy && namedBy.length > 0" class="grid-row-rules__named" data-grid-named-by>
            Named by {{ namedBy.join(', ') }}. Renaming or removing this row leaves them pointing at nothing, and publishing will refuse it.
        </p>
        <MdsButton
            size="sm"
            variant="tertiary"
            :icon-left="open ? 'chevron-down' : 'chevron-right'"
            :aria-expanded="open"
            :aria-controls="panelId"
            :data-grid-row-settings="index"
            @click="open = !open"
        >
            Row settings — {{ summary }}
        </MdsButton>
        <div v-show="open" :id="panelId" class="grid-row-rules__panel">
            <ConditionEditor
                :expression="row.relevant ?? null"
                :catalogue="catalogue"
                :references="references"
                legend="Show this row only when…"
                @update:expression="emit('update:row', withSetting('relevant', $event))"
            />
            <fieldset v-if="columns.length > 0" class="grid-row-rules__ticks" :data-grid-not-needed-ticks="index">
                <legend class="grid-row-rules__legend">Not needed in this row</legend>
                <MdsCheckbox
                    v-for="column in columns"
                    :key="column.value"
                    :label="column.label || column.value"
                    :model-value="notNeeded.includes(column.value)"
                    @update:model-value="toggleNotNeeded(column.value, $event)"
                />
            </fieldset>
            <section class="grid-row-rules__details" :aria-label="`Details for row ${index + 1}`">
                <h4 class="grid-row-rules__legend">Details under the row’s label</h4>
                <ContentBlocksEditor
                    purpose="row"
                    :blocks="details"
                    :form-id="formId"
                    @update:blocks="emit('update:row', withSetting('details', $event))"
                />
            </section>
        </div>
    </div>
</template>

<style scoped>
.grid-row-rules {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding-inline-start: var(--mds-space-3);
    border-inline-start: 2px solid var(--mds-color-border-default);
    min-width: 0;
}

.grid-row-rules__panel {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}

.grid-row-rules__ticks {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1) var(--mds-space-4);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

/* The full-width break belongs to the ticks' wrapping row only. On the details heading, inside a column, a 100% basis is
   a HEIGHT: wherever the config pane's height is definite (tablet) the heading grew to fill its section and pushed the
   details editor out over the next row. */
.grid-row-rules__ticks > .grid-row-rules__legend {
    flex: 1 0 100%;
}

.grid-row-rules__legend {
    margin: 0 0 var(--mds-space-1);
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.grid-row-rules__details {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.grid-row-rules__named {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-status-warning-fg);
}
</style>
