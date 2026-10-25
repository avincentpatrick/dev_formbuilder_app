<script setup lang="ts">
/**
 * Show only — narrow a question's choices, or one cascade level's, by what another form has recorded (M186, `R-ed7f3127`):
 * "only the barangays with a report in form X", or only those with none yet.
 *
 * The filter is one config value, `{form_id, field_key, mode}` — a cascade level's `filter`, or a list-fed question's
 * `options_filter` — and this emits it whole, or null to narrow nothing. The forms offered are the ones that share their
 * answers with this author (`linkable_sources`); a cascade there was ticked by its owner, and is offered only here.
 *
 * Only the questions whose answers can match these choices are offered (the server's `LinkedChoiceService::
 * comparableLevel()`): a Philippine places level is narrowed by the same level of another form's Philippine places
 * question, whatever either is called; any other cascade level by a level with the same key, or a question with one
 * answer; a question's own list by a question with one answer. Publish says the rest in words.
 *
 * A respondent sees the narrowed choices, the levels above narrowed to match (`D162`), and every choice with a note when
 * the other form cannot be checked (`D163`). An answer outside them is never refused.
 */
import { computed } from 'vue';
import { MdsFormField, MdsSelect } from '@meridian/design-system';
import type { ChoiceFilterValue, LinkableSource } from './types';

const props = defineProps<{
    filter: ChoiceFilterValue | null | undefined;
    sources: LinkableSource[];
    /** What is narrowed: a cascade level (its key, and the list it names), or a question's own list. */
    target: { kind: 'level'; key: string; list?: string } | { kind: 'list' };
    /** Says which level, for a screen reader, when several are listed one under another. */
    name?: string;
}>();

const emit = defineEmits<{ 'update:filter': [value: ChoiceFilterValue | null] }>();

type Question = LinkableSource['questions'][number];

/** Can this question's answers match the target's choices? */
function comparable(question: Question): boolean {
    const cascade = question.field_type === 'cascading_select';
    if (props.target.kind === 'list') return !cascade;

    const list = props.target.list ?? '';
    if (list.startsWith('psgc:')) return cascade && (question.levels ?? []).some((level) => level.list === list);

    return !cascade || (question.levels ?? []).some((level) => level.key !== '' && level.key === (props.target as { key: string }).key);
}

const offered = computed(() =>
    props.sources
        .map((source) => ({ ...source, questions: source.questions.filter(comparable) }))
        .filter((source) => source.questions.length > 0),
);

const mode = computed(() => props.filter?.mode ?? '');
const chosen = computed(() => offered.value.find((source) => source.id === props.filter?.form_id) ?? null);
/** A filter naming a form this author can no longer pick from — it stopped sharing, or the question no longer fits. */
const unavailable = computed(() => (props.filter?.form_id ?? null) !== null && chosen.value === null);

const MODES = [
    { value: '', label: 'Every choice' },
    { value: 'present', label: 'Only those another form has an answer for' },
    { value: 'absent', label: 'Only those another form has no answer for' },
];

function onMode(value: string): void {
    if (value !== 'present' && value !== 'absent') {
        emit('update:filter', null);

        return;
    }
    // A form sharing exactly one fitting question needs no further pick.
    const only = offered.value.length === 1 ? offered.value[0] : null;
    emit('update:filter', {
        form_id: props.filter?.form_id ?? only?.id ?? null,
        field_key: props.filter?.field_key ?? (only !== null && only.questions.length === 1 ? only.questions[0].key : null),
        mode: value,
    });
}

function onForm(formId: string): void {
    const picked = offered.value.find((source) => source.id === formId);
    emit('update:filter', {
        form_id: formId || null,
        field_key: picked?.questions.length === 1 ? picked.questions[0].key : null,
        mode: props.filter?.mode ?? 'present',
    });
}

function onQuestion(key: string): void {
    emit('update:filter', { form_id: props.filter?.form_id ?? null, field_key: key || null, mode: props.filter?.mode ?? 'present' });
}
</script>

<template>
    <div class="choice-filter" data-choice-filter>
        <MdsFormField v-slot="{ id, describedby, invalid }" :label="name ? `Show only — ${name}` : 'Show only'">
            <MdsSelect :id="id" :describedby="describedby" :invalid="invalid" :model-value="mode" :options="MODES" @update:model-value="onMode" />
        </MdsFormField>

        <template v-if="mode !== ''">
            <p v-if="offered.length === 0" class="choice-filter__text" data-choice-filter-empty>
                No form shares a question these choices can be narrowed by. Its owner can tick it in the form's Data sharing
                settings.
            </p>
            <p v-if="unavailable" class="choice-filter__warning" data-choice-filter-unavailable>
                The form these choices were narrowed by no longer shares that question with you. Choose another.
            </p>
            <template v-if="offered.length > 0">
                <MdsFormField v-slot="{ id, describedby, invalid }" label="Form">
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="chosen?.id ?? ''"
                        :options="offered.map((source) => ({ value: source.id, label: source.title }))"
                        placeholder="Choose a form"
                        @update:model-value="onForm"
                    />
                </MdsFormField>
                <MdsFormField
                    v-if="chosen"
                    v-slot="{ id, describedby, invalid }"
                    label="Question"
                    help="Its answers as they read when the form is opened. An answer already given is never refused."
                >
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="filter?.field_key ?? ''"
                        :options="chosen.questions.map((question) => ({ value: question.key, label: question.label }))"
                        placeholder="Choose a question"
                        @update:model-value="onQuestion"
                    />
                </MdsFormField>
            </template>
        </template>
    </div>
</template>

<style scoped>
.choice-filter {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.choice-filter__text {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.choice-filter__warning {
    margin: 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}
</style>
