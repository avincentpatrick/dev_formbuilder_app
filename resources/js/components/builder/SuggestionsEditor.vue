<script setup lang="ts">
/**
 * Where a Short text's suggestions come from (M184, `R-596d6afd`): none, a list file of this form, or another form's
 * answers to one of its questions — `config.suggestions_source = {kind: list, name} | {kind: form, form_id, field_key}`.
 * The person filling the form is offered them as they type and may still type anything: a suggestion never restricts,
 * and the server never checks an answer against one. A list's rows are written in when the form is published; another
 * form's answers are read as they stand, like a linked choice question's (`LinkedChoicesEditor`, whose house contract
 * this follows: it takes the question's config and emits a whole new one, and no store).
 */
import { computed, onMounted, ref, useId, watch } from 'vue';
import { MdsFormField, MdsRadio, MdsSelect } from '@meridian/design-system';
import ChoiceListUpload from '@/components/forms/ChoiceListUpload.vue';
import { describeChoiceList, listChoiceLists, type ChoiceListRow } from '@/components/forms/choice-lists';
import type { LinkableSource } from './types';

/** `ChoiceListMaterializer::FLAT_LIST_MAX` — the most rows one question suggests from a list. */
const FLAT_LIST_MAX = 5000;

const props = withDefaults(
    defineProps<{
        config: Record<string, unknown>;
        /** The forms that share answers with this author; none until the builder has read them. */
        sources?: LinkableSource[];
        /** The form whose list files the suggestions may come from; without it, no list file is offered. */
        formId?: string | null;
    }>(),
    { sources: () => [], formId: null },
);

const emit = defineEmits<{ 'update:config': [value: Record<string, unknown>] }>();

type Mode = 'none' | 'list' | 'form';
const source = computed<Record<string, unknown> | null>(() => {
    const raw = props.config.suggestions_source;

    return raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : null;
});
const mode = computed<Mode>(() => (source.value?.kind === 'list' ? 'list' : source.value?.kind === 'form' ? 'form' : 'none'));
const modeName = useId();

function emitSource(next: Record<string, unknown> | null): void {
    const config = { ...props.config };
    delete config.suggestions;
    if (next === null) delete config.suggestions_source;
    else config.suggestions_source = next;
    emit('update:config', config);
}

function onMode(value: string): void {
    if (value === mode.value) return;
    if (value === 'list') emitSource({ kind: 'list', name: '' });
    else if (value === 'form') emitSource({ kind: 'form', form_id: null, field_key: null });
    else emitSource(null);
}

// ── A list file ─────────────────────────────────────────────────────────────────────────────
const lists = ref<ChoiceListRow[]>([]);
const listName = computed(() => (typeof source.value?.name === 'string' ? source.value.name : ''));
const listOptions = computed(() => lists.value.map((list) => ({ value: list.name, label: `${list.name} — ${describeChoiceList(list)}` })));

async function loadLists(): Promise<void> {
    if (!props.formId) return;
    try {
        lists.value = await listChoiceLists(props.formId);
    } catch {
        // The picker shows what the question already names; the upload says why a file was refused.
    }
}
onMounted(() => {
    if (mode.value === 'list') void loadLists();
});
watch(mode, (next) => {
    if (next === 'list') void loadLists();
});

function onListUploaded(added: ChoiceListRow): void {
    lists.value = [...lists.value.filter((list) => list.name !== added.name), added].sort((a, b) => a.name.localeCompare(b.name));
    if (listName.value === '') emitSource({ kind: 'list', name: added.name });
}

const listNote = computed<string | null>(() => {
    if (listName.value === '') return null;
    const list = lists.value.find((candidate) => candidate.name === listName.value);
    if (list === undefined) return `${listName.value} is not uploaded to this form.`;
    if (list.row_count > FLAT_LIST_MAX) {
        return `This list has ${list.row_count.toLocaleString('en')} rows, and one question suggests at most 5,000.`;
    }

    return null;
});

// ── Another form's answers ──────────────────────────────────────────────────────────────────
const sourceFormId = computed(() => (typeof source.value?.form_id === 'string' ? source.value.form_id : null));
const chosenSource = computed<LinkableSource | null>(() => props.sources.find((s) => s.id === sourceFormId.value) ?? null);
const sourceOptions = computed(() => props.sources.map((s) => ({ value: s.id, label: s.title })));
const questionOptions = computed(() => (chosenSource.value?.questions ?? []).map((q) => ({ value: q.key, label: q.label })));
const unavailable = computed(() => sourceFormId.value !== null && chosenSource.value === null);

function onSource(id: string): void {
    const picked = props.sources.find((s) => s.id === id);
    // A form sharing exactly one question needs no second pick.
    const only = picked?.questions.length === 1 ? picked.questions[0].key : null;
    emitSource({ kind: 'form', form_id: id || null, field_key: only });
}

function onQuestion(key: string): void {
    emitSource({ kind: 'form', form_id: sourceFormId.value, field_key: key || null });
}
</script>

<template>
    <fieldset class="suggestions" data-suggestions-editor>
        <legend class="suggestions__legend">Suggestions come from</legend>
        <p class="suggestions__text">Offered as people type. They can still type any answer.</p>
        <div class="suggestions__modes">
            <MdsRadio :model-value="mode" value="none" label="No suggestions" :name="modeName" @update:model-value="onMode" />
            <MdsRadio v-if="formId" :model-value="mode" value="list" label="A list file" :name="modeName" @update:model-value="onMode" />
            <MdsRadio :model-value="mode" value="form" label="Another form's answers" :name="modeName" @update:model-value="onMode" />
        </div>

        <template v-if="mode === 'list' && formId">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="List file" help="The list's labels are suggested.">
                <MdsSelect
                    :id="id"
                    :describedby="describedby"
                    :invalid="invalid"
                    :model-value="listName"
                    :options="listOptions"
                    placeholder="Choose a list file"
                    @update:model-value="emitSource({ kind: 'list', name: $event })"
                />
            </MdsFormField>
            <p v-if="listNote" class="suggestions__warning" data-suggestions-list-note>{{ listNote }}</p>
            <ChoiceListUpload
                :form-id="formId"
                label="Upload a CSV or Excel file"
                help="Columns “name” (a code used once in the file) and “label”. An Excel file’s first sheet is read. The file’s name is the list’s name; uploading it again replaces the list. Up to 5,000 rows for one question."
                @uploaded="onListUploaded"
            />
        </template>

        <template v-if="mode === 'form'">
            <p v-if="sources.length === 0 && !unavailable" class="suggestions__text" data-suggestions-empty>
                No form shares its answers with you yet. A form's owner can share them in its Data sharing settings.
            </p>
            <p v-if="unavailable" class="suggestions__warning" data-suggestions-unavailable>
                The form these suggestions come from no longer shares them with you. Choose another, or turn suggestions off.
            </p>
            <template v-if="sources.length > 0">
                <MdsFormField v-slot="{ id, describedby, invalid }" label="Form">
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="chosenSource?.id ?? ''"
                        :options="sourceOptions"
                        placeholder="Choose a form"
                        @update:model-value="onSource"
                    />
                </MdsFormField>
                <MdsFormField
                    v-if="chosenSource"
                    v-slot="{ id, describedby, invalid }"
                    label="Question"
                    help="The answers given to this question are suggested, up to 1,000, as they read today."
                >
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="typeof source?.field_key === 'string' ? source.field_key : ''"
                        :options="questionOptions"
                        placeholder="Choose a question"
                        @update:model-value="onQuestion"
                    />
                </MdsFormField>
            </template>
        </template>
    </fieldset>
</template>

<style scoped>
.suggestions {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    border: 0;
}

.suggestions__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.suggestions__modes {
    display: flex;
    flex-direction: column;
}

.suggestions__text {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.suggestions__warning {
    margin: 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}
</style>
