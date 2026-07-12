<script setup lang="ts">
/**
 * Config sub-editor for a `likert_matrix` field (Increment G4b). The author defines the ROWS (each a
 * statement to rate) and the shared COLUMNS scale (the ordered rating points, lowest → highest); each row
 * renders one radio group over the scale. The publish gate verifies rows + columns are each non-empty with
 * distinct values. Each list is a plain `{value,label}[]` edited via the shared {@link GridOptionList};
 * every change emits a fresh array (one debounced history entry).
 */
import GridOptionList from './GridOptionList.vue';

interface Option {
    value: string;
    label: string;
}

defineProps<{ rows: Option[]; columns: Option[] }>();
const emit = defineEmits<{
    'update:rows': [value: Option[]];
    'update:columns': [value: Option[]];
}>();
</script>

<template>
    <div class="likert-matrix-editor">
        <GridOptionList
            heading="Rows"
            singular="Row"
            prefix="row"
            hint="Each row is a statement to rate."
            :options="rows"
            @update:options="emit('update:rows', $event)"
        />
        <GridOptionList
            heading="Scale points"
            singular="Point"
            prefix="col"
            hint="The shared rating scale (columns), from lowest to highest."
            :options="columns"
            @update:options="emit('update:columns', $event)"
        />
    </div>
</template>

<style scoped>
.likert-matrix-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}
</style>
