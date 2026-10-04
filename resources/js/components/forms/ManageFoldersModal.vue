<script setup lang="ts">
/**
 * "Manage folders" (M131, `R-9e634897`, `D78`, `D79`) — the forms list's one place to create, rename and
 * delete folders.
 *
 * `D79` splits the rights, and so does this dialog: anyone who may create forms (`folders.can.create`) gets
 * the create row; only Owners and Admins (`folders.can.manage`, which is `forms.edit.any`) get Rename and
 * Delete on each folder, because a folder holds other people's forms. The server enforces both through
 * `FormFolderPolicy` regardless of what is shown here.
 *
 * ⚠️ A DELETE UNFILES, AND THE CONFIRMATION SAYS SO IN THOSE WORDS. The database sets the folder's forms to
 * Unfiled (`forms_folder_fk`, `ON DELETE SET NULL`), and no form is ever deleted with its folder — the one
 * fact an author must not have to guess before pressing a destructive button.
 *
 * Every write is an Inertia visit that answers `back()`, so the list beneath re-renders with its counts.
 */
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsIcon, MdsModal, MdsTextInput } from '@meridian/design-system';
import type { FormListFolders } from '@/types/forms';

const props = defineProps<{
    open: boolean;
    folders: FormListFolders;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const createForm = useForm({ name: '' });
const renameForm = useForm({ name: '' });
const renaming = ref<string | null>(null);
const confirmingDelete = ref<string | null>(null);
const deleting = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        createForm.reset();
        createForm.clearErrors();
        renaming.value = null;
        confirmingDelete.value = null;
    },
    { immediate: true },
);

function create(): void {
    createForm.post('/form-folders', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => createForm.reset(),
    });
}

function startRename(id: string, name: string): void {
    confirmingDelete.value = null;
    renaming.value = id;
    renameForm.clearErrors();
    renameForm.name = name;
}

function saveRename(): void {
    if (renaming.value === null) return;
    renameForm.patch(`/form-folders/${renaming.value}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (renaming.value = null),
    });
}

function startDelete(id: string): void {
    renaming.value = null;
    confirmingDelete.value = id;
}

function confirmDelete(): void {
    if (confirmingDelete.value === null) return;
    deleting.value = true;
    router.delete(`/form-folders/${confirmingDelete.value}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (confirmingDelete.value = null),
        onFinish: () => (deleting.value = false),
    });
}

function countLabel(count: number): string {
    return count === 1 ? '1 form' : `${count} forms`;
}
</script>

<template>
    <MdsModal :open="open" title="Manage folders" @close="emit('update:open', false)">
        <div class="folders">
            <form v-if="folders.can.create" class="folders__create" @submit.prevent="create">
                <MdsFormField
                    v-slot="{ id, describedby, invalid }"
                    label="New folder"
                    help="Folders are shared by everyone in this workspace. They change nothing about who can open a form."
                    :error="createForm.errors.name"
                >
                    <MdsTextInput
                        :id="id"
                        v-model="createForm.name"
                        :describedby="describedby"
                        :invalid="invalid"
                        maxlength="80"
                        placeholder="Clinics"
                    />
                </MdsFormField>
                <MdsButton type="submit" variant="secondary" icon-left="plus" :loading="createForm.processing">
                    Add folder
                </MdsButton>
            </form>

            <p v-if="folders.options.length === 0" class="folders__empty">No folders yet.</p>

            <ul v-else class="folders__list" aria-label="Folders">
                <li v-for="folder in folders.options" :key="folder.id" class="folders__item" data-folder-entry>
                    <template v-if="renaming === folder.id">
                        <form class="folders__rename" @submit.prevent="saveRename">
                            <MdsFormField
                                v-slot="{ id, describedby, invalid }"
                                label="Folder name"
                                :error="renameForm.errors.name"
                            >
                                <MdsTextInput
                                    :id="id"
                                    v-model="renameForm.name"
                                    :describedby="describedby"
                                    :invalid="invalid"
                                    maxlength="80"
                                />
                            </MdsFormField>
                            <div class="folders__row-actions">
                                <MdsButton variant="tertiary" size="sm" @click="renaming = null">Cancel</MdsButton>
                                <MdsButton type="submit" variant="primary" size="sm" :loading="renameForm.processing">
                                    Save name
                                </MdsButton>
                            </div>
                        </form>
                    </template>

                    <template v-else-if="confirmingDelete === folder.id">
                        <p class="folders__confirm" role="alert">
                            Delete <strong>{{ folder.name }}</strong>? Its forms move to Unfiled. No form is deleted.
                        </p>
                        <div class="folders__row-actions">
                            <MdsButton variant="tertiary" size="sm" @click="confirmingDelete = null">Cancel</MdsButton>
                            <MdsButton variant="destructive" size="sm" icon-left="trash" :loading="deleting" @click="confirmDelete">
                                Delete folder
                            </MdsButton>
                        </div>
                    </template>

                    <template v-else>
                        <span class="folders__name">
                            <MdsIcon name="folder" size="sm" />
                            {{ folder.name }}
                        </span>
                        <span class="folders__count">{{ countLabel(folder.count) }}</span>
                        <div v-if="folders.can.manage" class="folders__row-actions">
                            <MdsButton variant="tertiary" size="sm" icon-left="edit" @click="startRename(folder.id, folder.name)">
                                Rename<span class="folders__hidden">{{ ` ${folder.name}` }}</span>
                            </MdsButton>
                            <MdsButton variant="tertiary" size="sm" icon-left="trash" @click="startDelete(folder.id)">
                                Delete<span class="folders__hidden">{{ ` ${folder.name}` }}</span>
                            </MdsButton>
                        </div>
                    </template>
                </li>
            </ul>
        </div>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Done</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.folders {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.folders__create {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-3);
}

.folders__create > :first-child {
    align-self: stretch;
}

.folders__empty,
.folders__confirm {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.folders__list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.folders__item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2) var(--mds-space-3);
    padding: var(--mds-space-3) 0;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.folders__name {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
    flex: 1 1 10rem;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
    font-weight: var(--mds-font-weight-medium);
}

.folders__count {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-variant-numeric: tabular-nums;
}

.folders__rename {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    flex: 1 1 100%;
}

.folders__row-actions {
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1);
}

/* The folder's name inside each Rename/Delete button, for a screen reader only — twenty buttons all named
   "Rename" are twenty buttons nobody can tell apart by name. */
.folders__hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
