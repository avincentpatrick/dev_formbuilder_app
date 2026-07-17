<script setup lang="ts">
/**
 * "Save as template" modal (Increment G9a) — shared by the forms Index row-action and the builder toolbar.
 * Snapshots the form's current draft into a tenant-owned private template (POST /forms/{id}/save-as-template).
 * The outcome surfaces as a Toast via the controller flash; on success the modal closes. Assembled from shared
 * design-system components.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsTextInput, MdsTextarea } from '@meridian/design-system';

const props = defineProps<{
    open: boolean;
    formId: string;
    defaultName?: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ name: '', description: '', category: '' });

// Seed the name with the form's title each time the modal opens, and clear stale errors. `immediate` so
// it also fires when the Index row-action mounts this modal already-open (v-if), not only on later toggles.
watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.name = props.defaultName ?? '';
        }
    },
    { immediate: true },
);

function submit(): void {
    form.post(`/forms/${props.formId}/save-as-template`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <MdsModal :open="open" title="Save as template" @close="emit('update:open', false)">
        <p class="save-template__prose">
            Save this form's current content as a reusable template. It stays private to your organization and
            can be used to start new forms.
        </p>
        <form class="save-template__form" @submit.prevent="submit">
            <MdsFormField label="Template name" required :error="form.errors.name" v-slot="{ id, describedby, invalid }">
                <MdsTextInput
                    :id="id"
                    v-model="form.name"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="Household survey"
                />
            </MdsFormField>
            <MdsFormField label="Category" :error="form.errors.category" v-slot="{ id, describedby, invalid }">
                <MdsTextInput
                    :id="id"
                    v-model="form.category"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="M&E / Research"
                />
            </MdsFormField>
            <MdsFormField label="Description" :error="form.errors.description" v-slot="{ id, describedby, invalid }">
                <MdsTextarea
                    :id="id"
                    v-model="form.description"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="What is this template for?"
                />
            </MdsFormField>
        </form>
        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="copy" :loading="form.processing" @click="submit">
                Save template
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.save-template__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.save-template__prose {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}
</style>
