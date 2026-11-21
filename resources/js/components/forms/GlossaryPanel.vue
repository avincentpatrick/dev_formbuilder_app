<script setup lang="ts">
/**
 * The Glossary section of a form's settings (M245, `R-d0bbb7bb`): the terms a form explains, and what each means.
 * A note's words marked `[[word]]` open their term's meaning in place, and the Glossary button on every page lists them
 * all. The glossary lives on the draft and is frozen with each published version, as choice lists are, so a change
 * reaches respondents when the form is published again.
 *
 * Read when the settings open, not passed in: the server is the one copy. Every change is announced in the status
 * region, and `changed` tells the builder so its preview shows the new meanings at once.
 */
import { ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsIconButton, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import { CONTENT_LIMITS } from '@/components/builder/content-blocks';
import { GLOSSARY_LIMITS, glossaryKey } from '@/components/builder/glossary';
import { addGlossaryTerm, listGlossary, removeGlossaryTerm, sortGlossary, updateGlossaryTerm, type GlossaryRow } from '@/components/forms/glossary';

const props = defineProps<{
    /** Whether the settings are open — the modal's state, or always true on the hub page. */
    open: boolean;
    formId: string;
}>();

const emit = defineEmits<{ changed: [rows: GlossaryRow[]] }>();

const rows = ref<GlossaryRow[]>([]);
const loadError = ref<string | null>(null);
const status = ref<string | null>(null);
const actionError = ref<string | null>(null);
const busy = ref(false);

// The add form.
const newTerm = ref('');
const newDefinition = ref('');

// The one row being changed in place, if any.
const editingId = ref<string | null>(null);
const editTerm = ref('');
const editDefinition = ref('');

async function load(): Promise<void> {
    loadError.value = null;
    try {
        rows.value = await listGlossary(props.formId);
    } catch (thrown) {
        loadError.value = thrown instanceof Error ? thrown.message : 'The glossary could not be read.';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) void load();
    },
    { immediate: true },
);

function settle(next: GlossaryRow[], message: string): void {
    rows.value = sortGlossary(next, glossaryKey);
    status.value = message;
    emit('changed', rows.value);
}

async function add(): Promise<void> {
    actionError.value = null;
    busy.value = true;
    try {
        const added = await addGlossaryTerm(props.formId, newTerm.value, newDefinition.value);
        settle([...rows.value, added], `Added ${added.term}.`);
        newTerm.value = '';
        newDefinition.value = '';
    } catch (thrown) {
        actionError.value = thrown instanceof Error ? thrown.message : 'The term was not added.';
    } finally {
        busy.value = false;
    }
}

function startEdit(row: GlossaryRow): void {
    actionError.value = null;
    editingId.value = row.id;
    editTerm.value = row.term;
    editDefinition.value = row.definition;
}

async function saveEdit(row: GlossaryRow): Promise<void> {
    actionError.value = null;
    busy.value = true;
    try {
        const saved = await updateGlossaryTerm(props.formId, row.id, editTerm.value, editDefinition.value);
        settle(
            rows.value.map((existing) => (existing.id === row.id ? saved : existing)),
            `Saved ${saved.term}.`,
        );
        editingId.value = null;
    } catch (thrown) {
        actionError.value = thrown instanceof Error ? thrown.message : 'The term was not saved.';
    } finally {
        busy.value = false;
    }
}

async function remove(row: GlossaryRow): Promise<void> {
    actionError.value = null;
    try {
        await removeGlossaryTerm(props.formId, row.id);
        settle(
            rows.value.filter((existing) => existing.id !== row.id),
            `Removed ${row.term}.`,
        );
    } catch (thrown) {
        actionError.value = thrown instanceof Error ? thrown.message : 'The term was not removed.';
    }
}
</script>

<template>
    <div class="glossary-panel">
        <p class="glossary-panel__intro">
            Explain the terms your form uses. In a note, mark a word as [[word]] and respondents can tap it to read its
            meaning; a Glossary button on every page lists them all, so terms in questions are covered too. Changes are
            saved to the draft and reach respondents when you publish. Shown in the form's default language only.
        </p>

        <p v-if="loadError" class="glossary-panel__error" role="alert">{{ loadError }}</p>
        <ul v-if="rows.length > 0" class="glossary-panel__list">
            <li v-for="row in rows" :key="row.id" class="glossary-panel__item" data-glossary-row>
                <template v-if="editingId === row.id">
                    <MdsFormField v-slot="{ id, describedby, invalid }" label="Term">
                        <MdsTextInput
                            :id="id"
                            v-model="editTerm"
                            :maxlength="CONTENT_LIMITS.maxTermLength"
                            :describedby="describedby"
                            :invalid="invalid"
                        />
                    </MdsFormField>
                    <MdsFormField v-slot="{ id, describedby, invalid }" label="Definition">
                        <MdsTextarea
                            :id="id"
                            v-model="editDefinition"
                            :rows="3"
                            :maxlength="GLOSSARY_LIMITS.maxDefinitionLength"
                            :describedby="describedby"
                            :invalid="invalid"
                        />
                    </MdsFormField>
                    <div class="glossary-panel__actions">
                        <MdsButton size="sm" :loading="busy" @click="saveEdit(row)">Save</MdsButton>
                        <MdsButton size="sm" variant="tertiary" @click="editingId = null">Cancel</MdsButton>
                    </div>
                </template>
                <template v-else>
                    <div class="glossary-panel__meta">
                        <span class="glossary-panel__term">{{ row.term }}</span>
                        <span class="glossary-panel__definition">{{ row.definition }}</span>
                    </div>
                    <div class="glossary-panel__actions">
                        <MdsIconButton icon="edit" size="sm" :label="`Change ${row.term}`" @click="startEdit(row)" />
                        <MdsIconButton icon="trash" size="sm" variant="danger" :label="`Remove ${row.term}`" @click="remove(row)" />
                    </div>
                </template>
            </li>
        </ul>
        <p v-else-if="!loadError" class="glossary-panel__empty">No terms yet.</p>

        <p v-if="actionError" class="glossary-panel__error" role="alert">{{ actionError }}</p>

        <form class="glossary-panel__add" @submit.prevent="add">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Term" help="A word or a short phrase, as your notes write it.">
                <MdsTextInput
                    :id="id"
                    v-model="newTerm"
                    :maxlength="CONTENT_LIMITS.maxTermLength"
                    :describedby="describedby"
                    :invalid="invalid"
                    data-glossary-new-term
                />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Definition" help="What it means, in one paragraph.">
                <MdsTextarea
                    :id="id"
                    v-model="newDefinition"
                    :rows="3"
                    :maxlength="GLOSSARY_LIMITS.maxDefinitionLength"
                    :describedby="describedby"
                    :invalid="invalid"
                    data-glossary-new-definition
                />
            </MdsFormField>
            <div class="glossary-panel__actions">
                <MdsButton type="submit" size="sm" icon-left="plus" :loading="busy" :disabled="rows.length >= GLOSSARY_LIMITS.maxEntries">
                    Add term
                </MdsButton>
            </div>
        </form>
        <p class="glossary-panel__status" role="status">{{ status ?? '' }}</p>
    </div>
</template>

<style scoped>
.glossary-panel {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.glossary-panel__intro,
.glossary-panel__empty,
.glossary-panel__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.glossary-panel__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.glossary-panel__item {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    min-width: 0;
}

.glossary-panel__item > :deep(.mds-form-field) {
    flex: 1 1 100%;
}

.glossary-panel__meta {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}

.glossary-panel__term {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.glossary-panel__definition {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.glossary-panel__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.glossary-panel__add {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.glossary-panel__error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
