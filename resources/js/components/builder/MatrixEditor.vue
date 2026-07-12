<script setup lang="ts">
/**
 * Config sub-editor for a `matrix` field (Increment G4b). The author defines the ROWS (each a question), the
 * COLUMNS (the grid's independent columns), and the shared CELL choice pool (each row×column cell is a
 * single-select over these). The runtime renders one select per cell; the publish gate verifies rows,
 * columns, and cells are each non-empty with distinct values. Each list is a plain `{value,label}[]` edited
 * via the shared {@link GridOptionList}; every change emits a fresh array (one debounced history entry).
 */
import GridOptionList from './GridOptionList.vue';

interface Option {
    value: string;
    label: string;
}

defineProps<{ rows: Option[]; columns: Option[]; cells: Option[] }>();
const emit = defineEmits<{
    'update:rows': [value: Option[]];
    'update:columns': [value: Option[]];
    'update:cells': [value: Option[]];
}>();
</script>

<template>
    <div class="matrix-editor">
        <GridOptionList
            heading="Rows"
            singular="Row"
            prefix="row"
            hint="Each row is a question."
            :options="rows"
            @update:options="emit('update:rows', $event)"
        />
        <GridOptionList
            heading="Columns"
            singular="Column"
            prefix="col"
            hint="The grid's columns."
            :options="columns"
            @update:options="emit('update:columns', $event)"
        />
        <GridOptionList
            heading="Cell choices"
            singular="Choice"
            prefix="cell"
            hint="Each cell is a single choice from this shared list."
            :options="cells"
            @update:options="emit('update:cells', $event)"
        />
    </div>
</template>

<style scoped>
.matrix-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}
</style>
