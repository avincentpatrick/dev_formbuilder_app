<script setup lang="ts">
/**
 * The Reference files settings section (M132, `R-bf49e4c1`): PDFs and images kept with a form for the people who build
 * and run it — a guide, a consent form, a map. Since M138 (`D92` = A) respondents are not shown them, and the section
 * says so first; `D92` rebuilds attached files Kobo-style, used inside the form. Frozen per version (`D61` = B).
 *
 * Its requests are `reference-files.ts`'s — JSON `fetch`, not Inertia visits, because the section is mounted in the
 * builder too, which never makes one, and an upload is multipart. The list it holds is the server's answer to the
 * last request, never a local guess.
 *
 * ⚠️ A NEW FILE IS "CHECKING" UNTIL ITS VIRUS CHECK HAS RUN, and it cannot be opened until then. The list is
 * read again every few seconds while any file is checking, a bounded number of times, the content-image editor's
 * retry; after the last try the section says what is happening instead of polling for ever.
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsIconButton, MdsTextInput } from '@meridian/design-system';
import {
    describeReferenceFile,
    listReferenceFiles,
    removeReferenceFile,
    renameReferenceFile,
    uploadReferenceFile,
} from '@/components/forms/reference-files';
import type { ReferenceFileRow } from '@/components/forms/types';

const props = defineProps<{
    /** Whether the SETTINGS are open — the modal's state, or always true on the hub page. See `ConfirmationPanel`. */
    open: boolean;
    formId: string;
    files: ReferenceFileRow[];
}>();

const RETRY_MS = 3000;
const MAX_RETRIES = 10;

const files = ref<ReferenceFileRow[]>([...props.files]);
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const status = ref<string | null>(null);
const actionError = ref<string | null>(null);
const editing = ref<string | null>(null);
const draftLabel = ref('');
const renameError = ref<string | null>(null);
const retries = ref(0);
let timer: number | null = null;

function stopPolling(): void {
    if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
    }
}

/** Read the list again while a file is still being checked; give up after the last try. */
function pollWhileChecking(): void {
    stopPolling();
    if (!files.value.some((file) => file.scan === 'checking') || retries.value >= MAX_RETRIES) {
        return;
    }
    timer = window.setTimeout(async () => {
        timer = null;
        retries.value += 1;
        try {
            files.value = await listReferenceFiles(props.formId);
        } catch {
            // A failed read is retried on the next tick; the list on screen stays what the server last said.
        }
        pollWhileChecking();
    }, RETRY_MS);
}

// Re-seed on open like every other section, which also shows what the server holds after a refused write.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            stopPolling();
            return;
        }
        files.value = [...props.files];
        editing.value = null;
        status.value = null;
        actionError.value = null;
        uploadError.value = null;
        retries.value = 0;
        pollWhileChecking();
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

async function onFileChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file === undefined || uploading.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = null;
    status.value = null;
    try {
        const added = await uploadReferenceFile(props.formId, file);
        files.value = [...files.value.filter((existing) => existing.id !== added.id), added];
        status.value = `Added ${added.label}.`;
        retries.value = 0;
        pollWhileChecking();
    } catch (thrown) {
        uploadError.value = thrown instanceof Error ? thrown.message : 'The file was not accepted. Please try another file.';
    } finally {
        uploading.value = false;
    }
}

function startRename(file: ReferenceFileRow): void {
    editing.value = file.id;
    draftLabel.value = file.label;
    renameError.value = null;
}

async function saveRename(file: ReferenceFileRow): Promise<void> {
    renameError.value = null;
    try {
        const renamed = await renameReferenceFile(props.formId, file.id, draftLabel.value);
        files.value = files.value.map((existing) => (existing.id === renamed.id ? renamed : existing));
        editing.value = null;
        status.value = `Renamed to ${renamed.label}.`;
    } catch (thrown) {
        renameError.value = thrown instanceof Error ? thrown.message : 'The name was not saved.';
    }
}

async function remove(file: ReferenceFileRow): Promise<void> {
    actionError.value = null;
    try {
        await removeReferenceFile(props.formId, file.id);
        files.value = files.value.filter((existing) => existing.id !== file.id);
        status.value = `Removed ${file.label}.`;
    } catch (thrown) {
        actionError.value = thrown instanceof Error ? thrown.message : 'The file was not removed.';
    }
}

function scanNote(file: ReferenceFileRow): string | null {
    if (file.scan === 'checking') {
        return retries.value >= MAX_RETRIES
            ? 'Still being checked for viruses. Reopen these settings in a minute.'
            : 'Checking this file for viruses…';
    }
    return file.scan === 'refused' ? 'The virus check refused this file. It cannot be opened; remove it.' : null;
}
</script>

<template>
    <div class="reference-files">
        <p class="reference-files__prose">
            Files kept with this form for the people who build and run it, such as a guide, a consent form or a map.
            They open from this list.
        </p>
        <!-- First, because it is the one fact about this section that surprises: respondents never see these (D92). -->
        <p class="reference-files__notice" data-reference-notice="staff-only">
            Respondents do not see these files. Using a file inside the form itself, as KoboToolbox does, comes later.
        </p>

        <ul v-if="files.length > 0" class="reference-files__list" aria-label="Reference files">
            <li v-for="file in files" :key="file.id" class="reference-files__item" :data-reference-file="file.id">
                <div class="reference-files__main">
                    <template v-if="editing === file.id">
                        <MdsFormField
                            v-slot="{ id, describedby, invalid }"
                            label="File name"
                            :error="renameError ?? undefined"
                        >
                            <MdsTextInput
                                :id="id"
                                v-model="draftLabel"
                                :aria-describedby="describedby"
                                :aria-invalid="invalid || undefined"
                                maxlength="120"
                                @keydown.enter.prevent="saveRename(file)"
                            />
                        </MdsFormField>
                        <div class="reference-files__rename-actions">
                            <MdsButton size="sm" variant="primary" @click="saveRename(file)">Save name</MdsButton>
                            <MdsButton size="sm" variant="tertiary" @click="editing = null">Cancel</MdsButton>
                        </div>
                    </template>
                    <template v-else>
                        <a v-if="file.scan === 'ready'" class="reference-files__name" :href="file.url" target="_blank" rel="noopener">
                            {{ file.label }}
                        </a>
                        <span v-else class="reference-files__name">{{ file.label }}</span>
                        <span class="reference-files__meta">{{ describeReferenceFile(file.mime_type, file.size_bytes) }}</span>
                        <span
                            v-if="scanNote(file) !== null"
                            class="reference-files__scan"
                            :class="{ 'reference-files__scan--refused': file.scan === 'refused' }"
                        >
                            {{ scanNote(file) }}
                        </span>
                    </template>
                </div>
                <div v-if="editing !== file.id" class="reference-files__actions">
                    <MdsIconButton icon="edit" size="sm" :label="`Rename ${file.label}`" @click="startRename(file)" />
                    <MdsIconButton icon="trash" size="sm" variant="danger" :label="`Remove ${file.label}`" @click="remove(file)" />
                </div>
            </li>
        </ul>
        <p v-else class="reference-files__empty">No reference files yet.</p>

        <p v-if="actionError" class="reference-files__error" role="alert">{{ actionError }}</p>

        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Attach a file"
            help="A PDF, or a PNG, JPEG or WebP image, up to 10 MB. It is added at the end of the list."
            :error="uploadError ?? undefined"
        >
            <input
                :id="id"
                class="reference-files__file"
                type="file"
                accept="application/pdf,image/png,image/jpeg,image/webp"
                :disabled="uploading"
                :aria-describedby="describedby"
                :aria-invalid="invalid || undefined"
                @change="onFileChosen"
            />
        </MdsFormField>
        <p class="reference-files__status" role="status">{{ uploading ? 'Uploading the file…' : (status ?? '') }}</p>
    </div>
</template>

<style scoped>
.reference-files {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.reference-files__prose {
    margin: 0;
    color: var(--mds-color-text-body);
}

.reference-files__notice {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-info-fg);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.reference-files__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.reference-files__item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-3);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-sm);
}

.reference-files__main {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
    flex: 1;
}

.reference-files__name {
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-medium);
    overflow-wrap: anywhere;
}

a.reference-files__name {
    color: var(--mds-color-action-primary-fg);
}

.reference-files__meta,
.reference-files__scan,
.reference-files__empty,
.reference-files__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.reference-files__scan--refused,
.reference-files__error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.reference-files__actions,
.reference-files__rename-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1);
}

.reference-files__file {
    max-width: 100%;
}
</style>
