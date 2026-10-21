<script setup lang="ts">
/**
 * A grid's layout (M183, `R-1c1d7266`), shared by {@link MatrixEditor} and {@link LikertMatrixEditor}: a table wherever
 * the grid has the room for one (the default, stored as nothing), or cards — one per row — at every width
 * (`config.layout: stacked`). One file, so the Help manual pins its label once.
 */
import { MdsFormField, MdsSelect } from '@meridian/design-system';

defineProps<{ layout: string | null }>();
const emit = defineEmits<{ 'update:layout': [value: string | null] }>();

const options = [
    { value: 'table', label: 'Table where it fits' },
    { value: 'stacked', label: 'Cards everywhere' },
];
</script>

<template>
    <div data-grid-layout>
        <MdsFormField label="Layout" help="A table on a wide screen and a card per row on a phone, or a card per row everywhere.">
            <template #default="{ id, describedby }">
                <MdsSelect
                    :id="id"
                    :describedby="describedby"
                    :model-value="layout === 'stacked' ? 'stacked' : 'table'"
                    :options="options"
                    @update:model-value="emit('update:layout', $event === 'stacked' ? 'stacked' : null)"
                />
            </template>
        </MdsFormField>
    </div>
</template>
