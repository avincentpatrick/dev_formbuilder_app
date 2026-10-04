<script setup lang="ts">
/**
 * "Move to folder" (M131, `R-9e634897`, `D78`) — files one form into a forms-list folder, or into Unfiled.
 *
 * Offered on a row only when `row.can.edit` holds, because filing is an edit of the form: the route is
 * `can:update,form` and nothing more, unlike "Set form scope" beside it, which grants access and is
 * Owner/Admin only. A folder grants nothing.
 *
 * '' is the Unfiled sentinel — `MdsSelect` models a plain string, and `submit()` sends the null the request
 * expects (`present|nullable`), the `ScopePanel` precedent.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsSelect } from '@meridian/design-system';
import type { FolderOption } from '@/types/forms';

const props = defineProps<{
    open: boolean;
    formId: string;
    formTitle: string;
    currentFolderId: string | null;
    folders: FolderOption[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ folder_id: '' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.folder_id = props.currentFolderId ?? '';
    },
    { immediate: true },
);

const options = computed(() => [
    { value: '', label: 'Unfiled' },
    ...props.folders.map((folder) => ({ value: folder.id, label: folder.name })),
]);

function submit(): void {
    form
        .transform((data) => ({ folder_id: data.folder_id === '' ? null : data.folder_id }))
        .patch(`/forms/${props.formId}/folder`, {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <MdsModal :open="open" title="Move to folder" @close="emit('update:open', false)">
        <form class="move-folder__form" @submit.prevent="submit">
            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Folder"
                :help="`Where “${formTitle}” is filed in the forms list. A folder changes nothing about who can open the form.`"
                :error="form.errors.folder_id"
            >
                <MdsSelect
                    :id="id"
                    v-model="form.folder_id"
                    :options="options"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
            <p v-if="folders.length === 0" class="move-folder__hint">
                This workspace has no folders yet. Create one with Manage folders, above the list.
            </p>
        </form>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="folder" :loading="form.processing" @click="submit">Move</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.move-folder__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.move-folder__hint {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
