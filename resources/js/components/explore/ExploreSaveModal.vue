<script setup lang="ts">
// Saving a chart from the builder (M235, ADR-0025 §D8): a name, and whether colleagues may open it.
//
// One modal for three verbs, and which one it is decides what is sent. A NEW chart and a COPY post the
// declaration on screen. DETAILS — renaming or sharing a chart already saved — sends the name and the switch
// and nothing else, so it cannot rewrite what the chart charts; saving changes to the declaration is the
// builder's own "Save changes" button.
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsSwitch, MdsTextInput } from '@meridian/design-system';
import { toPayload } from './definition';
import type { Definition, SavedChart } from './types';

const props = defineProps<{
    open: boolean;
    mode: 'create' | 'copy' | 'details';
    chart: SavedChart | null;
    definition: Definition;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm<{ name: string; is_shared: boolean; definition: Definition | null }>({
    name: '',
    is_shared: false,
    definition: null,
});

watch(
    () => [props.open, props.mode] as const,
    ([open, mode]) => {
        if (!open) return;

        form.name = mode === 'details' ? (props.chart?.name ?? '') : mode === 'copy' ? `${props.chart?.name ?? 'Chart'} (copy)` : '';
        form.is_shared = mode === 'details' ? (props.chart?.is_shared ?? false) : false;
        form.clearErrors();
    },
    { immediate: true },
);

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    if (props.mode === 'details' && props.chart !== null) {
        form.transform((data) => ({ name: data.name, is_shared: data.is_shared })).patch(`/explore/charts/${props.chart.id}`, {
            preserveScroll: true,
            onSuccess: close,
        });

        return;
    }

    form.transform((data) => ({ name: data.name, is_shared: data.is_shared, definition: toPayload(props.definition) })).post(
        '/explore/charts',
        { onSuccess: close },
    );
}
</script>

<template>
    <MdsModal
        :open="open"
        :title="mode === 'details' ? 'Name and sharing' : mode === 'copy' ? 'Save a copy' : 'Save this chart'"
        @close="close"
        @update:open="(v: boolean) => emit('update:open', v)"
    >
        <form @submit.prevent="submit">
            <MdsFormField
                label="Name"
                input-id="explore-save-name"
                :error="form.errors.name ?? form.errors.definition"
                required
            >
                <MdsTextInput
                    id="explore-save-name"
                    v-model="form.name"
                    :invalid="Boolean(form.errors.name)"
                    placeholder="Responses by channel, last 90 days"
                />
            </MdsFormField>

            <div class="explore-save__share">
                <MdsSwitch v-model="form.is_shared" label="Share with the workspace" describedby="explore-save-share-help" />
                <p id="explore-save-share-help" class="explore-save__help">
                    Colleagues who can open Explore will see this chart on their board. It is drawn from what
                    each of them can read, so it never shows anyone data they could not open themselves. Only
                    you can change or delete it.
                </p>
            </div>

            <div class="explore-save__actions">
                <MdsButton variant="tertiary" type="button" @click="close">Cancel</MdsButton>
                <MdsButton variant="primary" type="submit" :loading="form.processing" :disabled="form.name.trim() === ''">
                    {{ mode === 'details' ? 'Save' : 'Save chart' }}
                </MdsButton>
            </div>
        </form>
    </MdsModal>
</template>

<style scoped>
.explore-save__share {
    margin-top: var(--mds-space-4);
}

/* Zeroed on purpose: a paragraph inside a modal otherwise brings the browser's own margins with it. */
.explore-save__help {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.explore-save__actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-4);
}
</style>
