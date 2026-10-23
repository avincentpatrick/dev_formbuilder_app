<script setup lang="ts">
/**
 * How a repeatable section is drawn (M185, `R-0a7275ce`, `D129`): a card per entry (the default, stored as nothing), or a
 * table — every question a column, every entry a row, cards again on a phone. A section holding a question a table cell
 * cannot hold is drawn as cards whatever is chosen, and this says which question it is ({@link repeatTableBlocker}, the
 * twin of `App\Support\Forms\RepeatTable`, so the form, the PDF and this note agree). One file, so the Help manual pins its
 * label once, as {@link GridLayoutSelect} does for a grid's.
 */
import { MdsFormField, MdsSelect } from '@meridian/design-system';
import { computed } from 'vue';

import { repeatTableBlocker } from '../submissions/repeat-table';
import type { LocalField, PaletteGroup } from './types';

const props = defineProps<{
    layout: string | null | undefined;
    /** The section's questions, in order. */
    members: LocalField[];
    /** For a question type's name in the note. */
    palette: PaletteGroup[];
}>();
const emit = defineEmits<{ 'update:layout': [value: 'table' | null] }>();

const options = [
    { value: 'cards', label: 'A card per entry' },
    { value: 'table', label: 'A table, a row per entry' },
];

const blocker = computed(() => (props.layout === 'table' ? repeatTableBlocker(props.members) : null));

const typeName = (type: string): string =>
    props.palette.flatMap((group) => group.types).find((entry) => entry.value === type)?.label ?? type.replace(/_/g, ' ');
</script>

<template>
    <div data-repeat-layout>
        <MdsFormField label="Layout" help="A table shows every question as a column and every entry as a row. Phones still show a card per entry.">
            <template #default="{ id, describedby }">
                <MdsSelect
                    :id="id"
                    :describedby="describedby"
                    :model-value="layout === 'table' ? 'table' : 'cards'"
                    :options="options"
                    @update:model-value="emit('update:layout', $event === 'table' ? 'table' : null)"
                />
            </template>
        </MdsFormField>
        <p v-if="blocker" class="repeat-layout__note" data-repeat-layout-blocker>
            Shown as cards: “{{ blocker.label || blocker.key }}” ({{ typeName(blocker.field_type) }}) cannot be a table column. A table holds
            text, numbers, dates and choices.
        </p>
    </div>
</template>

<style scoped>
.repeat-layout__note {
    margin: var(--mds-space-2) 0 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}
</style>
