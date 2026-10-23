<script setup lang="ts">
/**
 * Config sub-editor for a `likert_matrix` field (Increment G4b). The author defines the ROWS (each a
 * statement to rate) and the shared COLUMNS scale (the ordered rating points, lowest → highest); each row
 * renders one radio group over the scale. The publish gate verifies rows + columns are each non-empty with
 * distinct values. Each list is a plain `{value,label}[]` edited via the shared {@link GridOptionList};
 * every change emits a fresh array (one debounced history entry). M183 (`R-1c1d7266`): a Layout too ({@link GridLayoutSelect}).
 *
 * M185 (`R-bb79aec0`, `D161`): the statements may come from a list file or another form's answers, as a Grid's rows may
 * (`MatrixEditor.vue` says how); the scale stays typed here.
 */
import { computed } from 'vue';

import { asChoiceConfig, slotIsSourced, withChoiceConfig } from './grid-sources';
import GridLayoutSelect from './GridLayoutSelect.vue';
import GridOptionList from './GridOptionList.vue';
import LinkedChoicesEditor from './LinkedChoicesEditor.vue';
import type { LinkableSource } from './types';

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    rows: Option[];
    columns: Option[];
    layout?: string | null;
    /** M185: the grid's whole config — where its rows come from. Without it, no source is offered. */
    config?: Record<string, unknown> | null;
    sources?: LinkableSource[];
    formId?: string | null;
}>();
const emit = defineEmits<{
    'update:rows': [value: Option[]];
    'update:columns': [value: Option[]];
    'update:layout': [value: string | null];
    'update:config': [value: Record<string, unknown>];
}>();

const rowsSourced = computed(() => (props.config ? slotIsSourced(props.config, 'rows') : false));

function setRows(view: Record<string, unknown>): void {
    if (props.config) emit('update:config', withChoiceConfig(props.config, 'rows', view));
}
</script>

<template>
    <div class="likert-matrix-editor">
        <LinkedChoicesEditor
            v-if="config"
            kind="rows"
            :config="asChoiceConfig(config, 'rows')"
            :sources="sources ?? []"
            :form-id="formId"
            data-grid-rows-source
            @update:config="setRows"
        />
        <GridOptionList
            v-if="!rowsSourced"
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
        <GridLayoutSelect :layout="layout ?? null" @update:layout="emit('update:layout', $event)" />
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
