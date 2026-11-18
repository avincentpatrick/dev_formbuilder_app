<script setup lang="ts">
/**
 * M246 (`grid-question-table`) — one Grid column's own rules, under the column in the Grid tab: when the column is shown,
 * in each row when its cell is needed, and in each row when its cell is required. Folded behind a "Column rules" button
 * that says what is set while it is closed.
 *
 * The column's show-when reads other questions and other grids' always-asked cells (`D230`). Its two per-row rules also
 * read "This row's …" for each column to its left (`${.col}`) — a column to the right is not decided yet, so it is never
 * offered, and publish refuses one typed by hand.
 */
import { MdsButton } from '@meridian/design-system';
import { computed, ref, useId } from 'vue';

import ConditionEditor from './ConditionEditor.vue';
import type { ReferenceCandidate } from './reference-candidates';
import type { ConditionCatalogue, ConditionFieldOption } from './types';

type Column = { value: string; label: string; required?: boolean; relevant?: string | null; needed_when?: string | null; required_when?: string | null };

const props = defineProps<{
    column: Column;
    index: number;
    catalogue: ConditionCatalogue;
    /** "This row's …" for each column to the left of this one. */
    rowCells: ConditionFieldOption[];
    references?: ReferenceCandidate[];
    /** What names this column elsewhere — renaming or removing it would leave them pointing at nothing. */
    namedBy?: string[];
}>();
const emit = defineEmits<{ 'update:column': [column: Column] }>();

const open = ref(false);
const panelId = useId();

/** Its per-row rules read this row's cells to the left first, then everything a show-when may read. */
const rowCatalogue = computed<ConditionCatalogue>(() => ({ ...props.catalogue, fields: [...props.rowCells, ...props.catalogue.fields] }));

const set = (text: string | null | undefined): boolean => typeof text === 'string' && text.trim() !== '';
const summary = computed<string>(() => {
    const parts = [
        set(props.column.relevant) ? 'shown only sometimes' : null,
        set(props.column.needed_when) ? 'needed only sometimes' : null,
        set(props.column.required_when) && props.column.required !== true ? 'required only sometimes' : null,
    ].filter((part): part is string => part !== null);

    return parts.length > 0 ? parts.join(' · ') : 'none set';
});

function withRule(key: 'relevant' | 'needed_when' | 'required_when', value: string | null): Column {
    const next: Column = { ...props.column };
    if (value === null || value.trim() === '') {
        delete next[key];
    } else {
        next[key] = value;
    }

    return next;
}
</script>

<template>
    <div class="grid-column-rules" :data-grid-column-rules="index">
        <p v-if="namedBy && namedBy.length > 0" class="grid-column-rules__named" data-grid-named-by>
            Named by {{ namedBy.join(', ') }}. Renaming or removing this column leaves them pointing at nothing, and publishing will refuse it.
        </p>
        <MdsButton
            size="sm"
            variant="tertiary"
            :icon-left="open ? 'chevron-down' : 'chevron-right'"
            :aria-expanded="open"
            :aria-controls="panelId"
            :data-grid-column-settings="index"
            @click="open = !open"
        >
            Column rules — {{ summary }}
        </MdsButton>
        <div v-show="open" :id="panelId" class="grid-column-rules__panel">
            <ConditionEditor
                :expression="column.relevant ?? null"
                :catalogue="catalogue"
                :references="references"
                legend="Show this column only when…"
                @update:expression="emit('update:column', withRule('relevant', $event))"
            />
            <ConditionEditor
                :expression="column.needed_when ?? null"
                :catalogue="rowCatalogue"
                :references="references"
                legend="In each row, this cell is needed only when…"
                @update:expression="emit('update:column', withRule('needed_when', $event))"
            />
            <ConditionEditor
                v-if="column.required !== true"
                :expression="column.required_when ?? null"
                :catalogue="rowCatalogue"
                :references="references"
                legend="In each row, this cell is required when…"
                @update:expression="emit('update:column', withRule('required_when', $event))"
            />
            <p v-else class="grid-column-rules__note">Required in every row is on, so this cell is always required where it is asked.</p>
        </div>
    </div>
</template>

<style scoped>
.grid-column-rules {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.grid-column-rules__panel {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}

.grid-column-rules__note,
.grid-column-rules__named {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.grid-column-rules__named {
    color: var(--mds-color-status-warning-fg);
}
</style>
