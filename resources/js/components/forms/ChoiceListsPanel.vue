<script setup lang="ts">
/**
 * The Choice lists section of a form's settings (M141, `R-f69aab42`, `D92` = A, `D95`): the CSV files the form's
 * cascading questions take their choices from, one per level, in Kobo's format. A cascade level names its list in the
 * builder's Levels tab, where a file can be uploaded too; this section shows every list the draft holds, its size and
 * columns, and removes one. Publishing freezes the lists with the version.
 *
 * Read when the settings open, not passed in: the lists live on the draft and change from two places (here and the
 * Levels tab), so the server is the one copy.
 */
import { ref, watch } from 'vue';
import { MdsFormField, MdsIconButton } from '@meridian/design-system';
import { describeChoiceList, listChoiceLists, removeChoiceList, uploadChoiceList, type ChoiceListRow } from '@/components/forms/choice-lists';

const props = defineProps<{
    /** Whether the settings are open — the modal's state, or always true on the hub page. */
    open: boolean;
    formId: string;
}>();

const lists = ref<ChoiceListRow[]>([]);
const loadError = ref<string | null>(null);
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const status = ref<string | null>(null);
const actionError = ref<string | null>(null);

async function load(): Promise<void> {
    loadError.value = null;
    try {
        lists.value = await listChoiceLists(props.formId);
    } catch (thrown) {
        loadError.value = thrown instanceof Error ? thrown.message : 'The lists could not be read.';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) void load();
    },
    { immediate: true },
);

async function onFileChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file === undefined || uploading.value) return;

    uploading.value = true;
    uploadError.value = null;
    status.value = null;
    try {
        const added = await uploadChoiceList(props.formId, file);
        lists.value = [...lists.value.filter((list) => list.name !== added.name), added].sort((a, b) => a.name.localeCompare(b.name));
        status.value = `Uploaded ${added.name}: ${describeChoiceList(added)}.`;
    } catch (thrown) {
        uploadError.value = thrown instanceof Error ? thrown.message : 'The file was not accepted.';
    } finally {
        uploading.value = false;
    }
}

async function remove(list: ChoiceListRow): Promise<void> {
    actionError.value = null;
    try {
        await removeChoiceList(props.formId, list.name);
        lists.value = lists.value.filter((existing) => existing.name !== list.name);
        status.value = `Removed ${list.name}.`;
    } catch (thrown) {
        actionError.value = thrown instanceof Error ? thrown.message : 'The list was not removed.';
    }
}
</script>

<template>
    <div class="choice-lists">
        <p class="choice-lists__intro">
            Long lists, such as region, province, city or municipality, and barangay, can come from CSV files instead of
            being typed. Upload one file per level, then choose it on each level of a cascading question.
        </p>

        <p v-if="loadError" class="choice-lists__error" role="alert">{{ loadError }}</p>
        <ul v-if="lists.length > 0" class="choice-lists__list">
            <li v-for="list in lists" :key="list.name" class="choice-lists__item">
                <div class="choice-lists__meta">
                    <span class="choice-lists__name">{{ list.name }}</span>
                    <span class="choice-lists__detail">{{ describeChoiceList(list) }} · columns: {{ list.columns.join(', ') }}</span>
                </div>
                <MdsIconButton icon="trash" size="sm" variant="danger" :label="`Remove ${list.name}`" @click="remove(list)" />
            </li>
        </ul>
        <p v-else-if="!loadError" class="choice-lists__empty">No choice lists yet.</p>

        <p v-if="actionError" class="choice-lists__error" role="alert">{{ actionError }}</p>

        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Upload a CSV file"
            help="Columns “name” (a code used once in the file) and “label”; below the first level, add a column named after the level above. The file’s name is the list’s name, and uploading it again replaces the list. Up to 10 MB."
            :error="uploadError ?? undefined"
        >
            <input
                :id="id"
                class="choice-lists__file"
                type="file"
                accept=".csv,text/csv"
                :disabled="uploading"
                :aria-describedby="describedby"
                :aria-invalid="invalid || undefined"
                @change="onFileChosen"
            />
        </MdsFormField>
        <p class="choice-lists__status" role="status">{{ uploading ? 'Reading the file…' : (status ?? '') }}</p>
    </div>
</template>

<style scoped>
.choice-lists {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.choice-lists__intro,
.choice-lists__empty,
.choice-lists__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.choice-lists__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.choice-lists__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    min-width: 0;
}

.choice-lists__meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.choice-lists__name {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.choice-lists__detail {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.choice-lists__error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.choice-lists__file {
    max-width: 100%;
}
</style>
