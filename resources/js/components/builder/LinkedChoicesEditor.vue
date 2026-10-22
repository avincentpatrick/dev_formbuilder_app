<script setup lang="ts">
/**
 * Where a single-choice, multiple-choice (M184) or dropdown question's choices come from (M133, `R-5da4a30f` — Connect
 * project v1): typed here, or another form's answers to one of its questions.
 *
 * The link is one config key, `options_source = {form_id, field_key}`. The forms offered are the ones that share
 * their answers (their Data sharing settings) and whose responses this author can read — the server sends them as
 * `linkable_sources`, so nothing here decides who may see what. Whether the link can SERVE (the source still
 * shares the question, this form's owner can read it) is publish's to say, in words, because both keys can change
 * after the link is made.
 *
 * ⚠️ TYPED CHOICES AND A LINK NEVER LIVE TOGETHER. Both engines check an answer against the typed list whenever it
 * is non-empty, so a linked answer would be refused. Choosing "Another form's answers" over typed choices asks
 * first, and the choices go in the same change as the link — one undo entry, through the panel.
 *
 * ⚠️ IT TAKES THE QUESTION'S CONFIG AND EMITS A WHOLE NEW ONE, AND NO STORE — the sub-editor house contract
 * (`types.ts`, `ConditionCatalogue`). `ConfigPanel` writes what it emits in one assignment and one touch, which is
 * what makes "remove the typed choices and link" a single undo entry.
 *
 * ⚠️ RADIOS, NOT A SEGMENTED CONTROL. The Options tab already sits under the builder's one tablist, and a choice with
 * a sentence of consequence under it reads better as a group with a legend.
 *
 * ── A LIST FILE (M184, `R-3e7c81bb`) ─────────────────────────────────────────────────────────────────────────────
 * The third place: one of this form's list files (`options_list`, the list's name — never its rows, as a cascade level
 * names its list), written into the question's choices when the form is published. Offered when the panel passes the
 * form's id, which is where the lists are read and uploaded. One question takes one source: choosing any clears the
 * others' keys, in the same change. Past 30 choices a Single or Multiple choice says what `D160` chose — the paper prints
 * every choice — and points at a Dropdown; past 5,000 it says publish will refuse.
 */
import { computed, onMounted, ref, useId, watch } from 'vue';
import { MdsButton, MdsFormField, MdsRadio, MdsSelect } from '@meridian/design-system';
import ChoiceListUpload from '@/components/forms/ChoiceListUpload.vue';
import { describeChoiceList, listChoiceLists, type ChoiceListRow } from '@/components/forms/choice-lists';
import { choiceSourceOf } from './choice-source';
import type { LinkableSource, OptionsSource } from './types';

/** `D160`: past this many choices a Single or Multiple choice is better as a Dropdown, and the paper prints them all. */
const LONG_LIST = 30;
/** `ChoiceListMaterializer::FLAT_LIST_MAX` — the most rows one question takes from a list. */
const FLAT_LIST_MAX = 5000;

const props = defineProps<{
    /** The question's whole config: its `options_source` link or `options_list` name, if any, and its typed `options`. */
    config: Record<string, unknown>;
    sources: LinkableSource[];
    /** The form whose list files the question may take its choices from; without it, no list file is offered. */
    formId?: string | null;
    fieldType?: string;
}>();

const emit = defineEmits<{ 'update:config': [value: Record<string, unknown>] }>();

/** The config without one key — a source the question no longer takes its choices from. */
function without(config: Record<string, unknown>, key: string): Record<string, unknown> {
    const next = { ...config };
    delete next[key];

    return next;
}

/** The question's link, or null when its choices are typed here (a missing or null key alike). */
const source = computed<OptionsSource | null>(() => {
    const link = props.config.options_source;
    if (link === null || typeof link !== 'object') return null;
    const { form_id: formId, field_key: fieldKey } = link as Record<string, unknown>;

    return { form_id: typeof formId === 'string' ? formId : null, field_key: typeof fieldKey === 'string' ? fieldKey : null };
});

/** How many choices are typed here — what choosing a link would remove. */
const typedCount = computed(() => (Array.isArray(props.config.options) ? props.config.options.length : 0));

/** One new config: the link set (and typed choices cleared, never kept beside it), or the link removed. */
function emitSource(next: OptionsSource | null): void {
    const base = without(props.config, 'options_list');
    emit('update:config', next === null ? { ...base, options_source: null } : { ...base, options: [], options_source: next });
}

/** One new config: the question takes its choices from this list file ('' while the author is choosing one). */
function emitList(name: string): void {
    emit('update:config', { ...without(props.config, 'options_source'), options: [], options_list: name });
}

type Mode = 'here' | 'list' | 'form';
const mode = computed<Mode>(() => ({ typed: 'here', list: 'list', form: 'form' } as const)[choiceSourceOf(props.config)]);
const confirming = ref<'list' | 'form' | null>(null);
const modeName = useId();

// ── A list file (M184) ─────────────────────────────────────────────────────────────────────
const lists = ref<ChoiceListRow[]>([]);
const listName = computed(() => (typeof props.config.options_list === 'string' ? props.config.options_list : ''));
const chosenList = computed(() => lists.value.find((list) => list.name === listName.value) ?? null);
const listOptions = computed(() => lists.value.map((list) => ({ value: list.name, label: `${list.name} — ${describeChoiceList(list)}` })));

async function loadLists(): Promise<void> {
    if (!props.formId) return;
    try {
        lists.value = await listChoiceLists(props.formId);
    } catch {
        // The picker shows what the question already names; the upload says why a file was refused.
    }
}
// Read only once the question takes its choices from a list — not for every choice question the author opens.
onMounted(() => {
    if (mode.value === 'list') void loadLists();
});
watch(mode, (next) => {
    if (next === 'list') void loadLists();
});

function onListUploaded(added: ChoiceListRow): void {
    lists.value = [...lists.value.filter((list) => list.name !== added.name), added].sort((a, b) => a.name.localeCompare(b.name));
    if (listName.value === '') emitList(added.name);
}

/** Why the chosen list will not publish, or what it means on paper — or null. */
const listNote = computed<{ tone: 'warning' | 'text'; text: string } | null>(() => {
    if (listName.value === '') return null;
    const list = chosenList.value;
    if (list === null) return { tone: 'warning', text: `${listName.value} is not uploaded to this form.` };
    const size = list.row_count.toLocaleString('en');
    if (list.row_count > FLAT_LIST_MAX) {
        return { tone: 'warning', text: `This list has ${size} choices, and one question takes at most 5,000. Use a cascading select for a longer list.` };
    }
    if (list.row_count > FLAT_LIST_MAX && (props.fieldType === 'single_select' || props.fieldType === 'multi_select')) {
        return { tone: 'text', text: `This list has ${size} choices. A Dropdown is easier to use, and the paper form prints every choice.` };
    }

    return null;
});

const chosenSource = computed<LinkableSource | null>(() => props.sources.find((s) => s.id === source.value?.form_id) ?? null);

const sourceOptions = computed(() => props.sources.map((s) => ({ value: s.id, label: s.title })));
const questionOptions = computed(() => (chosenSource.value?.questions ?? []).map((q) => ({ value: q.key, label: q.label })));

/** A link this author can no longer pick from: the source stopped sharing, or they cannot read it. */
const unavailable = computed(() => source.value?.form_id != null && chosenSource.value === null);

function start(next: 'list' | 'form'): void {
    confirming.value = null;
    if (next === 'form') emitSource({ form_id: null, field_key: null });
    else emitList('');
}

function confirmStart(): void {
    if (confirming.value !== null) start(confirming.value);
}

function onMode(value: string): void {
    if (value === 'here') {
        confirming.value = null;
        if (mode.value !== 'here') emitSource(null);

        return;
    }
    const next = value === 'list' ? 'list' : 'form';
    if (mode.value === next) return;
    if (mode.value === 'here' && typedCount.value > 0) {
        confirming.value = next;

        return;
    }
    start(next);
}

function onSource(formId: string): void {
    const picked = props.sources.find((s) => s.id === formId);
    // A form sharing exactly one question needs no second pick.
    const only = picked?.questions.length === 1 ? picked.questions[0].key : null;
    emitSource({ form_id: formId || null, field_key: only });
}

function onQuestion(key: string): void {
    emitSource({ form_id: source.value?.form_id ?? null, field_key: key || null });
}
</script>

<template>
    <fieldset class="linked-choices" data-linked-choices>
        <legend class="linked-choices__legend">Choices come from</legend>
        <div class="linked-choices__modes">
            <MdsRadio
                :model-value="confirming ?? mode"
                value="here"
                label="Typed here"
                :name="modeName"
                @update:model-value="onMode"
            />
            <MdsRadio
                v-if="formId"
                :model-value="confirming ?? mode"
                value="list"
                label="A list file"
                :name="modeName"
                @update:model-value="onMode"
            />
            <MdsRadio
                :model-value="confirming ?? mode"
                value="form"
                label="Another form's answers"
                :name="modeName"
                @update:model-value="onMode"
            />
        </div>

        <div v-if="confirming" class="linked-choices__confirm" role="group" aria-label="Remove typed choices" data-linked-choices-confirm>
            <p class="linked-choices__text">
                The {{ typedCount }} {{ typedCount === 1 ? 'choice' : 'choices' }} typed here will be removed. A question
                takes its choices from one place.
            </p>
            <div class="linked-choices__actions">
                <MdsButton variant="tertiary" size="sm" @click="confirming = null">Keep typed choices</MdsButton>
                <MdsButton variant="primary" size="sm" @click="confirmStart">Remove and continue</MdsButton>
            </div>
        </div>

        <template v-if="mode === 'list' && formId">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="List file" help="Respondents choose from the list's rows: “name” is what is saved, “label” what they see.">
                <MdsSelect
                    :id="id"
                    :describedby="describedby"
                    :invalid="invalid"
                    :model-value="listName"
                    :options="listOptions"
                    placeholder="Choose a list file"
                    @update:model-value="emitList($event)"
                />
            </MdsFormField>
            <p v-if="listNote" :class="listNote.tone === 'warning' ? 'linked-choices__warning' : 'linked-choices__text'" data-linked-choices-list-note>
                {{ listNote.text }}
            </p>
            <ChoiceListUpload
                :form-id="formId"
                label="Upload a CSV or Excel file"
                help="Columns “name” (a code used once in the file) and “label”. An Excel file’s first sheet is read. The file’s name is the list’s name; uploading it again replaces the list. Up to 5,000 choices for one question."
                @uploaded="onListUploaded"
            />
        </template>

        <template v-if="mode === 'form'">
            <p v-if="sources.length === 0 && !unavailable" class="linked-choices__text" data-linked-choices-empty>
                No form shares its answers with you yet. A form's owner can share them in its Data sharing settings.
            </p>
            <p v-if="unavailable" class="linked-choices__warning" data-linked-choices-unavailable>
                The form this question takes its choices from no longer shares them with you. Choose another, or type
                the choices here.
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
                    help="Respondents choose from the answers given to this question, up to 1,000, as they read today."
                >
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="source?.field_key ?? ''"
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
.linked-choices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    border: 0;
}

.linked-choices__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.linked-choices__modes {
    display: flex;
    flex-direction: column;
}

.linked-choices__text {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.linked-choices__warning {
    margin: 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.linked-choices__confirm {
    padding: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    border-radius: var(--mds-radius-sm);
    background: var(--mds-color-bg-sunken);
}

.linked-choices__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-2);
}
</style>
