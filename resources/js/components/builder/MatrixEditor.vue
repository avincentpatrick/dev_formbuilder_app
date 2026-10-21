<script setup lang="ts">
/**
 * Config sub-editor for a `matrix` field (Increment G4b). The author defines the ROWS (each a question), the
 * COLUMNS (the grid's independent columns), and the shared CELL choice pool. The publish gate verifies rows and
 * columns are each non-empty with distinct values. Each list is a plain `{value,label}[]` edited via the shared
 * {@link GridOptionList}; every change emits a fresh array (one debounced history entry).
 *
 * M183 (`R-5033e87e`, `D129`): each column takes its own Type (the six of `MatrixColumns::TYPES` — never Yes/No, `D140`),
 * a Required flag (its cell in every row) and, as a choice column, a list of its own; a choice column with none draws
 * on the Cell choices, and the hint under them names the columns that do. A column keeps every key it already had; a
 * column whose new type takes no choices drops its own. Both grids also take a Layout ({@link GridLayoutSelect}).
 */
import { MdsCheckbox, MdsFormField, MdsSelect } from '@meridian/design-system';
import { computed } from 'vue';

import GridLayoutSelect from './GridLayoutSelect.vue';
import GridOptionList from './GridOptionList.vue';

interface Option {
    value: string;
    label: string;
}

/** A matrix column as stored: its value and label, its own type, choices and required flag, and any other key it had. */
interface Column extends Option {
    type?: string;
    options?: Option[];
    required?: boolean;
    [key: string]: unknown;
}

const COLUMN_TYPES = [
    { value: 'single_select', label: 'Single choice' },
    { value: 'multi_select', label: 'Multiple choice' },
    { value: 'short_text', label: 'Short text' },
    { value: 'integer', label: 'Whole number' },
    { value: 'decimal', label: 'Decimal number' },
    { value: 'date', label: 'Date' },
];
const CHOICE_TYPES = new Set(['single_select', 'multi_select']);

const props = defineProps<{ rows: Option[]; columns: Column[]; cells: Option[]; layout?: string | null }>();
const emit = defineEmits<{
    'update:rows': [value: Option[]];
    'update:columns': [value: Column[]];
    'update:cells': [value: Option[]];
    'update:layout': [value: string | null];
}>();

/** A column's type as the grid reads it: none, or one a grid cannot hold, is a single choice. */
function typeOf(column: Column): string {
    return typeof column.type === 'string' && COLUMN_TYPES.some((t) => t.value === column.type) ? column.type : 'single_select';
}

function takesChoices(column: Column): boolean {
    return CHOICE_TYPES.has(typeOf(column));
}

function patchColumn(index: number, patch: (column: Column) => Column): void {
    emit('update:columns', props.columns.map((column, i) => (i === index ? patch(column) : column)));
}

function setType(index: number, type: string): void {
    patchColumn(index, (column) => {
        const next: Column = { ...column, type };
        if (!CHOICE_TYPES.has(type)) {
            delete next.options;
        }

        return next;
    });
}

function setRequired(index: number, required: boolean): void {
    patchColumn(index, (column) => {
        const next: Column = { ...column, required };
        if (!required) {
            delete next.required;
        }

        return next;
    });
}

function setOptions(index: number, options: Option[]): void {
    patchColumn(index, (column) => {
        const next: Column = { ...column, options };
        if (options.length === 0) {
            delete next.options;
        }

        return next;
    });
}

/** The choice columns with no list of their own — the ones the Cell choices serve. */
const cellChoiceUsers = computed(() =>
    props.columns.filter((column) => takesChoices(column) && (column.options ?? []).length === 0).map((column) => column.label || column.value),
);
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
            hint="Each column is asked in every row, with its own type of answer."
            :options="columns"
            @update:options="emit('update:columns', $event)"
        >
            <template #details="{ option, index }">
                <div class="matrix-editor__column">
                    <MdsFormField label="Type">
                        <template #default="{ id, describedby }">
                            <MdsSelect
                                :id="id"
                                :describedby="describedby"
                                :data-column-type="index"
                                :model-value="typeOf(option)"
                                :options="COLUMN_TYPES"
                                @update:model-value="setType(index, $event)"
                            />
                        </template>
                    </MdsFormField>
                    <MdsCheckbox
                        :data-column-required="index"
                        label="Required in every row"
                        :model-value="option.required === true"
                        @update:model-value="setRequired(index, $event)"
                    />
                    <div v-if="takesChoices(option)" :data-column-choices="index">
                        <GridOptionList
                            :heading="`Choices for ${option.label || option.value}`"
                            singular="Choice"
                            prefix="choice"
                            hint="Leave this empty to use the Cell choices below."
                            :options="option.options ?? []"
                            @update:options="setOptions(index, $event)"
                        />
                    </div>
                </div>
            </template>
        </GridOptionList>
        <GridOptionList
            heading="Cell choices"
            singular="Choice"
            prefix="cell"
            hint="The choices of every choice column that has no list of its own."
            :options="cells"
            @update:options="emit('update:cells', $event)"
        />
        <p class="matrix-editor__users" data-cell-choices-users>
            {{ cellChoiceUsers.length > 0 ? `Used by: ${cellChoiceUsers.join(', ')}.` : 'No column uses these: every choice column has its own list.' }}
        </p>
        <GridLayoutSelect :layout="layout ?? null" @update:layout="emit('update:layout', $event)" />
    </div>
</template>

<style scoped>
.matrix-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

/* A column's own settings sit under its row, set in from the list so they read as that column's. */
.matrix-editor__column {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
    padding-inline-start: var(--mds-space-3);
    border-inline-start: 2px solid var(--mds-color-border-default);
}

/* Pulled up under the Cell choices it describes. */
.matrix-editor__users {
    margin: calc(-1 * var(--mds-space-3)) 0 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
